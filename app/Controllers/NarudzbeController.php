<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Db;

/**
 * Narudžbe. Checkout pretvara košaricu korisnika u narudžbu u JEDNOJ transakciji
 * (provjera i umanjenje zalihe, prijenos stavki, pražnjenje košarice).
 */
final class NarudzbeController extends BaseController
{
    private const STATUS_WHITELIST = ['nova', 'placena', 'poslana', 'dostavljena', 'otkazana'];

    /**
     * POST /api/narudzbe - checkout trenutne košarice (bez tijela).
     * Transakcija: zaključaj zalihe (FOR UPDATE) -> provjeri -> kreiraj narudžbu i stavke ->
     * umanji zalihe -> isprazni košaricu -> commit. Bilo kakva greška -> rollback.
     */
    public function store(Request $req, Response $res): void
    {
        $korisnik = $this->traziKorisnika($res);
        if ($korisnik === null) {
            return;
        }
        $korisnikId = (int) $korisnik['id'];

        try {
            $conn = Db::connect();

            // 1) Dohvati i ZAKLJUČAJ stavke košarice s pripadnim proizvodima
            $sqlK = 'SELECT sk.proizvod_id, sk.kolicina, p.naziv, p.cijena, p.zaliha
                     FROM   stavke_kosarice sk
                     JOIN   proizvodi p ON sk.proizvod_id = p.id
                     WHERE  sk.korisnik_id = :kid
                     ORDER BY p.naziv
                     FOR UPDATE OF p.zaliha';
            $stmtK = oci_parse($conn, $sqlK);
            oci_bind_by_name($stmtK, ':kid', $korisnikId);
            if (!@oci_execute($stmtK, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmtK);
                oci_free_statement($stmtK);
                throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
            }
            $stavke = [];
            while ($r = oci_fetch_assoc($stmtK)) {
                $stavke[] = $r;
            }
            oci_free_statement($stmtK);

            if (count($stavke) === 0) {
                oci_rollback($conn);
                $res->error('Košarica je prazna', 422);
                return;
            }

            // 2) Provjeri zalihe i izračunaj ukupan iznos
            $ukupno = 0.0;
            foreach ($stavke as $s) {
                if ((int) $s['KOLICINA'] > (int) $s['ZALIHA']) {
                    oci_rollback($conn);
                    $res->error('Nedovoljna zaliha za "' . $s['NAZIV'] . '" (dostupno: ' . (int) $s['ZALIHA'] . ')', 409);
                    return;
                }
                $ukupno += (float) $s['CIJENA'] * (int) $s['KOLICINA'];
            }
            $ukupno = round($ukupno, 2);

            // 3) Kreiraj zaglavlje narudžbe
            $stmtN = oci_parse($conn, 'INSERT INTO narudzbe (korisnik_id, ukupno)
                                       VALUES (:kid, :ukupno) RETURNING id INTO :nid');
            $nid = 0;
            oci_bind_by_name($stmtN, ':kid',    $korisnikId);
            oci_bind_by_name($stmtN, ':ukupno', $ukupno);
            oci_bind_by_name($stmtN, ':nid',    $nid, 10, SQLT_INT);
            if (!@oci_execute($stmtN, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmtN);
                oci_free_statement($stmtN);
                throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
            }
            oci_free_statement($stmtN);

            // 4) Prebaci stavke i umanji zalihe
            foreach ($stavke as $s) {
                $pid = (int) $s['PROIZVOD_ID'];
                $kol = (int) $s['KOLICINA'];
                $cij = (float) $s['CIJENA'];

                $stmtS = oci_parse($conn, 'INSERT INTO stavke_narudzbe (narudzba_id, proizvod_id, kolicina, cijena)
                                           VALUES (:nid, :pid, :kol, :cij)');
                oci_bind_by_name($stmtS, ':nid', $nid);
                oci_bind_by_name($stmtS, ':pid', $pid);
                oci_bind_by_name($stmtS, ':kol', $kol);
                oci_bind_by_name($stmtS, ':cij', $cij);
                if (!@oci_execute($stmtS, OCI_NO_AUTO_COMMIT)) {
                    $err = oci_error($stmtS);
                    oci_free_statement($stmtS);
                    throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
                }
                oci_free_statement($stmtS);

                $stmtU = oci_parse($conn, 'UPDATE proizvodi SET zaliha = zaliha - :kol WHERE id = :pid');
                oci_bind_by_name($stmtU, ':kol', $kol);
                oci_bind_by_name($stmtU, ':pid', $pid);
                if (!@oci_execute($stmtU, OCI_NO_AUTO_COMMIT)) {
                    $err = oci_error($stmtU);
                    oci_free_statement($stmtU);
                    throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
                }
                oci_free_statement($stmtU);
            }

            // 5) Isprazni košaricu
            $stmtD = oci_parse($conn, 'DELETE FROM stavke_kosarice WHERE korisnik_id = :kid');
            oci_bind_by_name($stmtD, ':kid', $korisnikId);
            if (!@oci_execute($stmtD, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmtD);
                oci_free_statement($stmtD);
                throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
            }
            oci_free_statement($stmtD);

            oci_commit($conn);
            $res->ok(['id' => (int) $nid, 'ukupno' => (float) $ukupno], 'Narudžba kreirana', 201);
        } catch (\Throwable $e) {
            if (isset($conn)) {
                oci_rollback($conn);
            }
            error_log($e->getMessage());
            $res->error('Interna greška servera', 500);
        }
    }

    /** GET /api/narudzbe - kupac vidi svoje, admin sve (paginirano). */
    public function index(Request $req, Response $res): void
    {
        $korisnik = $this->traziKorisnika($res);
        if ($korisnik === null) {
            return;
        }
        $jeAdmin    = $this->jeAdmin($korisnik);
        $korisnikId = (int) $korisnik['id'];

        $stranica   = max(1, (int) ($req->query('stranica') ?? 1));
        $poStranici = max(1, min(50, (int) ($req->query('po_stranici') ?? 10)));
        $offset     = ($stranica - 1) * $poStranici;

        $filter = $jeAdmin ? '' : 'WHERE n.korisnik_id = :kid';

        try {
            $conn = Db::connect();

            $stmtBroj = oci_parse($conn, "SELECT COUNT(*) AS ukupno FROM narudzbe n $filter");
            if (!$jeAdmin) {
                oci_bind_by_name($stmtBroj, ':kid', $korisnikId);
            }
            oci_execute($stmtBroj);
            $redBroj = oci_fetch_assoc($stmtBroj);
            oci_free_statement($stmtBroj);
            $ukupnoBroj = (int) $redBroj['UKUPNO'];

            $sql  = "SELECT n.id, n.korisnik_id,
                            TO_CHAR(n.datum, 'YYYY-MM-DD HH24:MI:SS') AS datum,
                            n.status, n.ukupno
                     FROM   narudzbe n
                     $filter
                     ORDER BY n.datum DESC
                     OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";
            $stmt = oci_parse($conn, $sql);
            if (!$jeAdmin) {
                oci_bind_by_name($stmt, ':kid', $korisnikId);
            }
            oci_bind_by_name($stmt, ':offset', $offset);
            oci_bind_by_name($stmt, ':limit',  $poStranici);
            oci_execute($stmt);

            $narudzbe = [];
            while ($red = oci_fetch_assoc($stmt)) {
                $narudzbe[] = [
                    'id'          => (int) $red['ID'],
                    'korisnik_id' => (int) $red['KORISNIK_ID'],
                    'datum'       => (string) $red['DATUM'],
                    'status'      => (string) $red['STATUS'],
                    'ukupno'      => (float) $red['UKUPNO'],
                ];
            }
            oci_free_statement($stmt);

            $res->ok([
                'narudzbe'   => $narudzbe,
                'paginacija' => [
                    'stranica'    => $stranica,
                    'po_stranici' => (int) $poStranici,
                    'ukupno'      => $ukupnoBroj,
                    'stranica_od' => (int) ceil($ukupnoBroj / $poStranici),
                ],
            ]);
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            $res->error('Interna greška servera', 500);
        }
    }

    /** GET /api/narudzbe?id=N - narudžba sa stavkama (kupac samo svoju, admin bilo koju). */
    public function show(Request $req, Response $res): void
    {
        $korisnik = $this->traziKorisnika($res);
        if ($korisnik === null) {
            return;
        }
        $id = $this->pozitivanId($req->query('id'), $res);
        if ($id === null) {
            return;
        }
        $jeAdmin    = $this->jeAdmin($korisnik);
        $korisnikId = (int) $korisnik['id'];

        try {
            $conn = Db::connect();

            $sqlN = "SELECT n.id, n.korisnik_id,
                            TO_CHAR(n.datum, 'YYYY-MM-DD HH24:MI:SS') AS datum,
                            n.status, n.ukupno
                     FROM   narudzbe n
                     WHERE  n.id = :id";
            $stmtN = oci_parse($conn, $sqlN);
            oci_bind_by_name($stmtN, ':id', $id);
            oci_execute($stmtN);
            $glava = oci_fetch_assoc($stmtN);
            oci_free_statement($stmtN);

            if (!$glava) {
                $res->error('Narudžba nije pronađena', 404);
                return;
            }
            if (!$jeAdmin && (int) $glava['KORISNIK_ID'] !== $korisnikId) {
                $res->error('Nemate pristup ovoj narudžbi', 403);
                return;
            }

            $sqlS = 'SELECT sn.proizvod_id, sn.kolicina, sn.cijena, p.naziv
                     FROM   stavke_narudzbe sn
                     JOIN   proizvodi p ON sn.proizvod_id = p.id
                     WHERE  sn.narudzba_id = :nid
                     ORDER BY p.naziv';
            $stmtS = oci_parse($conn, $sqlS);
            oci_bind_by_name($stmtS, ':nid', $id);
            oci_execute($stmtS);

            $stavke = [];
            while ($red = oci_fetch_assoc($stmtS)) {
                $stavke[] = [
                    'proizvod_id' => (int) $red['PROIZVOD_ID'],
                    'naziv'       => (string) $red['NAZIV'],
                    'kolicina'    => (int) $red['KOLICINA'],
                    'cijena'      => (float) $red['CIJENA'],
                    'iznos'       => round((float) $red['CIJENA'] * (int) $red['KOLICINA'], 2),
                ];
            }
            oci_free_statement($stmtS);

            $res->ok([
                'id'          => (int) $glava['ID'],
                'korisnik_id' => (int) $glava['KORISNIK_ID'],
                'datum'       => (string) $glava['DATUM'],
                'status'      => (string) $glava['STATUS'],
                'ukupno'      => (float) $glava['UKUPNO'],
                'stavke'      => $stavke,
            ]);
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            $res->error('Interna greška servera', 500);
        }
    }

    /** PUT /api/narudzbe?id=N - promjena statusa (samo admin). */
    public function update(Request $req, Response $res): void
    {
        if ($this->traziAdmina($res) === null) {
            return;
        }
        $id = $this->pozitivanId($req->query('id'), $res);
        if ($id === null) {
            return;
        }
        $tijelo = $this->jsonTijelo($req, $res);
        if ($tijelo === null) {
            return;
        }

        $status = trim((string) ($tijelo['status'] ?? ''));
        if (!in_array($status, self::STATUS_WHITELIST, true)) {
            $res->error('Polje status mora biti jedno od: ' . implode(', ', self::STATUS_WHITELIST), 422);
            return;
        }

        try {
            $conn = Db::connect();
            $stmt = oci_parse($conn, 'UPDATE narudzbe SET status = :status WHERE id = :id');
            oci_bind_by_name($stmt, ':status', $status);
            oci_bind_by_name($stmt, ':id',     $id);

            if (!@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
            }
            $azurirano = oci_num_rows($stmt);
            oci_free_statement($stmt);

            if ($azurirano === 0) {
                oci_rollback($conn);
                $res->error('Narudžba nije pronađena', 404);
                return;
            }
            oci_commit($conn);
            $res->ok(['id' => (int) $id, 'status' => $status], 'Status narudžbe ažuriran');
        } catch (\Throwable $e) {
            if (isset($conn)) {
                oci_rollback($conn);
            }
            error_log($e->getMessage());
            $res->error('Interna greška servera', 500);
        }
    }

    /**
     * DELETE /api/narudzbe?id=N - brisanje narudžbe (vlasnik ili admin).
     * Transakcija: vrati zalihe -> obriši stavke -> obriši narudžbu.
     */
    public function destroy(Request $req, Response $res): void
    {
        $korisnik = $this->traziKorisnika($res);
        if ($korisnik === null) {
            return;
        }
        $id = $this->pozitivanId($req->query('id'), $res);
        if ($id === null) {
            return;
        }
        $jeAdmin    = $this->jeAdmin($korisnik);
        $korisnikId = (int) $korisnik['id'];

        try {
            $conn = Db::connect();

            // Provjeri postojanje i vlasništvo
            $stmtN = oci_parse($conn, 'SELECT korisnik_id FROM narudzbe WHERE id = :id');
            oci_bind_by_name($stmtN, ':id', $id);
            oci_execute($stmtN);
            $glava = oci_fetch_assoc($stmtN);
            oci_free_statement($stmtN);

            if (!$glava) {
                $res->error('Narudžba nije pronađena', 404);
                return;
            }
            if (!$jeAdmin && (int) $glava['KORISNIK_ID'] !== $korisnikId) {
                $res->error('Nemate pristup ovoj narudžbi', 403);
                return;
            }

            // Vrati zalihe za sve stavke ove narudžbe
            $stmtV = oci_parse($conn,
                'UPDATE proizvodi p
                 SET    p.zaliha = p.zaliha + (
                            SELECT sn.kolicina FROM stavke_narudzbe sn
                            WHERE  sn.proizvod_id = p.id AND sn.narudzba_id = :nid
                        )
                 WHERE  p.id IN (SELECT proizvod_id FROM stavke_narudzbe WHERE narudzba_id = :nid2)');
            oci_bind_by_name($stmtV, ':nid',  $id);
            oci_bind_by_name($stmtV, ':nid2', $id);
            if (!@oci_execute($stmtV, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmtV);
                oci_free_statement($stmtV);
                throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
            }
            oci_free_statement($stmtV);

            // Obriši stavke pa zaglavlje
            $stmtDS = oci_parse($conn, 'DELETE FROM stavke_narudzbe WHERE narudzba_id = :nid');
            oci_bind_by_name($stmtDS, ':nid', $id);
            if (!@oci_execute($stmtDS, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmtDS);
                oci_free_statement($stmtDS);
                throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
            }
            oci_free_statement($stmtDS);

            $stmtDN = oci_parse($conn, 'DELETE FROM narudzbe WHERE id = :nid');
            oci_bind_by_name($stmtDN, ':nid', $id);
            if (!@oci_execute($stmtDN, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmtDN);
                oci_free_statement($stmtDN);
                throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
            }
            oci_free_statement($stmtDN);

            oci_commit($conn);
            $res->ok(null, 'Narudžba obrisana, zalihe vraćene');
        } catch (\Throwable $e) {
            if (isset($conn)) {
                oci_rollback($conn);
            }
            error_log($e->getMessage());
            $res->error('Interna greška servera', 500);
        }
    }
}
