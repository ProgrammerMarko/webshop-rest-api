<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Db;

/**
 * Proizvodi = kolekcionarske novčanice.
 * Veza N:1 prema kategorijama (kategorija = šira država-grupa).
 */
final class ProizvodiController extends BaseController
{
    private const SORT_WHITELIST      = ['id', 'naziv', 'cijena', 'godina', 'zaliha'];
    private const OCUVANOST_WHITELIST = ['UNC', 'AU', 'XF', 'VF', 'F', 'VG', 'G'];
    private const GODINA_MIN          = 1800;
    private const GODINA_MAX          = 2100;

    /** GET /api/proizvodi - javna paginirana lista s pretragom, filtrima i sortiranjem. */
    public function index(Request $req, Response $res): void
    {
        $stranica   = max(1, (int) ($req->query('stranica') ?? 1));
        $poStranici = max(1, min(50, (int) ($req->query('po_stranici') ?? 10)));
        $offset     = ($stranica - 1) * $poStranici;

        $q      = trim((string) ($req->query('q') ?? ''));
        $qParam = $q !== '' ? '%' . $q . '%' : '%';

        $sort  = in_array($req->query('sort') ?? '', self::SORT_WHITELIST, true)
            ? $req->query('sort')
            : 'id';
        $smjer = strtolower((string) ($req->query('smjer') ?? '')) === 'desc' ? 'DESC' : 'ASC';

        // Opcijski filteri
        $kategorijaId = $req->query('kategorija_id');
        if ($kategorijaId !== null) {
            if (!ctype_digit((string) $kategorijaId)) {
                $res->error('Parametar kategorija_id mora biti cijeli broj', 422);
                return;
            }
            $kategorijaId = (int) $kategorijaId;
        }

        $godina = $req->query('godina');
        if ($godina !== null) {
            if (!ctype_digit((string) $godina)) {
                $res->error('Parametar godina mora biti cijeli broj', 422);
                return;
            }
            $godina = (int) $godina;
        }

        $ocuvanost = $req->query('ocuvanost');
        if ($ocuvanost !== null && !in_array($ocuvanost, self::OCUVANOST_WHITELIST, true)) {
            $res->error('Parametar ocuvanost mora biti jedna od: ' . implode(', ', self::OCUVANOST_WHITELIST), 422);
            return;
        }

        $uvjeti = ['(UPPER(p.naziv) LIKE UPPER(:q) OR UPPER(p.zemlja) LIKE UPPER(:q))'];
        if ($kategorijaId !== null) { $uvjeti[] = 'p.kategorija_id = :kat'; }
        if ($godina !== null)       { $uvjeti[] = 'p.godina = :god'; }
        if ($ocuvanost !== null)    { $uvjeti[] = 'p.ocuvanost = :ocu'; }
        $where = 'WHERE ' . implode(' AND ', $uvjeti);

        try {
            $conn = Db::connect();

            $stmtBroj = oci_parse($conn, "SELECT COUNT(*) AS ukupno FROM proizvodi p $where");
            oci_bind_by_name($stmtBroj, ':q', $qParam);
            if ($kategorijaId !== null) { oci_bind_by_name($stmtBroj, ':kat', $kategorijaId); }
            if ($godina !== null)       { oci_bind_by_name($stmtBroj, ':god', $godina); }
            if ($ocuvanost !== null)    { oci_bind_by_name($stmtBroj, ':ocu', $ocuvanost); }
            oci_execute($stmtBroj);
            $redBroj = oci_fetch_assoc($stmtBroj);
            oci_free_statement($stmtBroj);
            $ukupno = (int) $redBroj['UKUPNO'];

            $sql  = "SELECT p.id, p.naziv, p.opis, p.zemlja, p.godina, p.ocuvanost,
                            p.kataloski_broj, p.cijena, p.zaliha,
                            k.id AS kat_id, k.naziv AS kat_naziv
                     FROM   proizvodi p
                     LEFT JOIN kategorije k ON p.kategorija_id = k.id
                     $where
                     ORDER BY p.$sort $smjer
                     OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";
            $stmt = oci_parse($conn, $sql);
            oci_bind_by_name($stmt, ':q', $qParam);
            if ($kategorijaId !== null) { oci_bind_by_name($stmt, ':kat', $kategorijaId); }
            if ($godina !== null)       { oci_bind_by_name($stmt, ':god', $godina); }
            if ($ocuvanost !== null)    { oci_bind_by_name($stmt, ':ocu', $ocuvanost); }
            oci_bind_by_name($stmt, ':offset', $offset);
            oci_bind_by_name($stmt, ':limit',  $poStranici);
            oci_execute($stmt);

            $proizvodi = [];
            while ($red = oci_fetch_assoc($stmt)) {
                $proizvodi[] = $this->mapiraj($red);
            }
            oci_free_statement($stmt);

            $res->ok([
                'proizvodi'  => $proizvodi,
                'paginacija' => [
                    'stranica'    => $stranica,
                    'po_stranici' => (int) $poStranici,
                    'ukupno'      => $ukupno,
                    'stranica_od' => (int) ceil($ukupno / $poStranici),
                ],
            ]);
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            $res->error('Interna greška servera', 500);
        }
    }

    /** GET /api/proizvodi?id=N - javni prikaz jedne novčanice. */
    public function show(Request $req, Response $res): void
    {
        $id = $this->pozitivanId($req->query('id'), $res);
        if ($id === null) {
            return;
        }

        try {
            $conn = Db::connect();
            $sql  = 'SELECT p.id, p.naziv, p.opis, p.zemlja, p.godina, p.ocuvanost,
                            p.kataloski_broj, p.cijena, p.zaliha,
                            k.id AS kat_id, k.naziv AS kat_naziv
                     FROM   proizvodi p
                     LEFT JOIN kategorije k ON p.kategorija_id = k.id
                     WHERE  p.id = :id';
            $stmt = oci_parse($conn, $sql);
            oci_bind_by_name($stmt, ':id', $id);
            oci_execute($stmt);
            $red = oci_fetch_assoc($stmt);
            oci_free_statement($stmt);

            if (!$red) {
                $res->error('Novčanica nije pronađena', 404);
                return;
            }
            $res->ok($this->mapiraj($red));
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            $res->error('Interna greška servera', 500);
        }
    }

    /** POST /api/proizvodi - kreiranje (samo admin). */
    public function store(Request $req, Response $res): void
    {
        if ($this->traziAdmina($res) === null) {
            return;
        }
        $tijelo = $this->jsonTijelo($req, $res);
        if ($tijelo === null) {
            return;
        }

        $polja = $this->validiraj($tijelo, $res);
        if ($polja === null) {
            return;
        }

        try {
            $conn = Db::connect();
            $sql  = 'INSERT INTO proizvodi
                        (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
                     VALUES
                        (:naziv, :opis, :zemlja, :godina, :ocuvanost, :kataloski, :cijena, :zaliha, :kategorija_id)
                     RETURNING id INTO :novi_id';
            $stmt   = oci_parse($conn, $sql);
            $noviId = 0;
            oci_bind_by_name($stmt, ':naziv',         $polja['naziv']);
            oci_bind_by_name($stmt, ':opis',          $polja['opis']);
            oci_bind_by_name($stmt, ':zemlja',        $polja['zemlja']);
            oci_bind_by_name($stmt, ':godina',        $polja['godina']);
            oci_bind_by_name($stmt, ':ocuvanost',     $polja['ocuvanost']);
            oci_bind_by_name($stmt, ':kataloski',     $polja['kataloski_broj']);
            oci_bind_by_name($stmt, ':cijena',        $polja['cijena']);
            oci_bind_by_name($stmt, ':zaliha',        $polja['zaliha']);
            oci_bind_by_name($stmt, ':kategorija_id', $polja['kategorija_id']);
            oci_bind_by_name($stmt, ':novi_id',       $noviId, 10, SQLT_INT);

            if (!@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
            }
            oci_free_statement($stmt);
            oci_commit($conn);

            $res->ok(['id' => (int) $noviId], 'Novčanica kreirana', 201);
        } catch (\Throwable $e) {
            if (isset($conn)) {
                oci_rollback($conn);
            }
            $this->mapirajGresku($e, $res);
        }
    }

    /** PUT /api/proizvodi?id=N - ažuriranje (samo admin). */
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
        $polja = $this->validiraj($tijelo, $res);
        if ($polja === null) {
            return;
        }

        try {
            $conn = Db::connect();
            $sql  = 'UPDATE proizvodi SET
                        naziv = :naziv, opis = :opis, zemlja = :zemlja, godina = :godina,
                        ocuvanost = :ocuvanost, kataloski_broj = :kataloski,
                        cijena = :cijena, zaliha = :zaliha, kategorija_id = :kategorija_id
                     WHERE id = :id';
            $stmt = oci_parse($conn, $sql);
            oci_bind_by_name($stmt, ':naziv',         $polja['naziv']);
            oci_bind_by_name($stmt, ':opis',          $polja['opis']);
            oci_bind_by_name($stmt, ':zemlja',        $polja['zemlja']);
            oci_bind_by_name($stmt, ':godina',        $polja['godina']);
            oci_bind_by_name($stmt, ':ocuvanost',     $polja['ocuvanost']);
            oci_bind_by_name($stmt, ':kataloski',     $polja['kataloski_broj']);
            oci_bind_by_name($stmt, ':cijena',        $polja['cijena']);
            oci_bind_by_name($stmt, ':zaliha',        $polja['zaliha']);
            oci_bind_by_name($stmt, ':kategorija_id', $polja['kategorija_id']);
            oci_bind_by_name($stmt, ':id',            $id);

            if (!@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
            }
            $azurirano = oci_num_rows($stmt);
            oci_free_statement($stmt);

            if ($azurirano === 0) {
                oci_rollback($conn);
                $res->error('Novčanica nije pronađena', 404);
                return;
            }
            oci_commit($conn);
            $res->ok(['id' => (int) $id], 'Novčanica ažurirana');
        } catch (\Throwable $e) {
            if (isset($conn)) {
                oci_rollback($conn);
            }
            $this->mapirajGresku($e, $res);
        }
    }

    /** DELETE /api/proizvodi?id=N - brisanje (samo admin). */
    public function destroy(Request $req, Response $res): void
    {
        if ($this->traziAdmina($res) === null) {
            return;
        }
        $id = $this->pozitivanId($req->query('id'), $res);
        if ($id === null) {
            return;
        }

        try {
            $conn = Db::connect();
            $stmt = oci_parse($conn, 'DELETE FROM proizvodi WHERE id = :id');
            oci_bind_by_name($stmt, ':id', $id);

            if (!@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
            }
            $obrisano = oci_num_rows($stmt);
            oci_free_statement($stmt);

            if ($obrisano === 0) {
                oci_rollback($conn);
                $res->error('Novčanica nije pronađena', 404);
                return;
            }
            oci_commit($conn);
            $res->ok(null, 'Novčanica obrisana');
        } catch (\Throwable $e) {
            if (isset($conn)) {
                oci_rollback($conn);
            }
            if (str_contains($e->getMessage(), 'ORA-02292')) {
                $res->error('Novčanica se ne može obrisati jer postoji u nekoj narudžbi', 409);
                return;
            }
            error_log($e->getMessage());
            $res->error('Interna greška servera', 500);
        }
    }

    // ---- pomoćne metode ----

    /** Pretvori Oracle redak u JSON strukturu novčanice. */
    private function mapiraj(array $red): array
    {
        return [
            'id'             => (int) $red['ID'],
            'naziv'          => (string) $red['NAZIV'],
            'opis'           => $red['OPIS'] !== null ? (string) $red['OPIS'] : null,
            'zemlja'         => $red['ZEMLJA'] !== null ? (string) $red['ZEMLJA'] : null,
            'godina'         => $red['GODINA'] !== null ? (int) $red['GODINA'] : null,
            'ocuvanost'      => $red['OCUVANOST'] !== null ? (string) $red['OCUVANOST'] : null,
            'kataloski_broj' => $red['KATALOSKI_BROJ'] !== null ? (string) $red['KATALOSKI_BROJ'] : null,
            'cijena'         => (float) $red['CIJENA'],
            'zaliha'         => (int) $red['ZALIHA'],
            'kategorija'     => $red['KAT_ID'] !== null ? [
                'id'    => (int) $red['KAT_ID'],
                'naziv' => (string) $red['KAT_NAZIV'],
            ] : null,
        ];
    }

    /**
     * Validacija ulaznih polja za store/update.
     * Vraća normalizirana polja (s null-ovima za prazne) ili null uz poslan 422.
     */
    private function validiraj(array $tijelo, Response $res): ?array
    {
        $naziv     = trim((string) ($tijelo['naziv'] ?? ''));
        $opis      = trim((string) ($tijelo['opis'] ?? ''));
        $zemlja    = trim((string) ($tijelo['zemlja'] ?? ''));
        $kataloski = trim((string) ($tijelo['kataloski_broj'] ?? ''));

        if ($naziv === '') {
            $res->error('Polje naziv je obavezno', 422);
            return null;
        }
        if (mb_strlen($naziv) > 200) {
            $res->error('Polje naziv ne smije biti dulje od 200 znakova', 422);
            return null;
        }
        if (mb_strlen($opis) > 1000) {
            $res->error('Polje opis ne smije biti dulje od 1000 znakova', 422);
            return null;
        }
        if (mb_strlen($zemlja) > 80) {
            $res->error('Polje zemlja ne smije biti dulje od 80 znakova', 422);
            return null;
        }
        if (mb_strlen($kataloski) > 50) {
            $res->error('Polje kataloski_broj ne smije biti dulje od 50 znakova', 422);
            return null;
        }

        // cijena: obavezna, broj >= 0
        $cijena = $tijelo['cijena'] ?? null;
        if (!is_int($cijena) && !is_float($cijena)) {
            $res->error('Polje cijena je obavezno i mora biti broj', 422);
            return null;
        }
        if ($cijena < 0) {
            $res->error('Polje cijena ne smije biti negativno', 422);
            return null;
        }

        // zaliha: obavezna, cijeli broj >= 0
        $zaliha = $tijelo['zaliha'] ?? null;
        if (!is_int($zaliha) || $zaliha < 0) {
            $res->error('Polje zaliha je obavezno i mora biti cijeli broj >= 0', 422);
            return null;
        }

        // godina: opcijska, cijeli broj u rasponu
        $godina = $tijelo['godina'] ?? null;
        if ($godina !== null) {
            if (!is_int($godina) || $godina < self::GODINA_MIN || $godina > self::GODINA_MAX) {
                $res->error('Polje godina mora biti cijeli broj između ' . self::GODINA_MIN . ' i ' . self::GODINA_MAX, 422);
                return null;
            }
        }

        // ocuvanost: opcijska, iz whitelist-e
        $ocuvanost = $tijelo['ocuvanost'] ?? null;
        if ($ocuvanost !== null && $ocuvanost !== '') {
            if (!in_array($ocuvanost, self::OCUVANOST_WHITELIST, true)) {
                $res->error('Polje ocuvanost mora biti jedno od: ' . implode(', ', self::OCUVANOST_WHITELIST), 422);
                return null;
            }
        } else {
            $ocuvanost = null;
        }

        // kategorija_id: opcijska, pozitivan cijeli broj
        $kategorijaId = $tijelo['kategorija_id'] ?? null;
        if ($kategorijaId !== null) {
            if (!is_int($kategorijaId) || $kategorijaId < 1) {
                $res->error('Polje kategorija_id mora biti pozitivan cijeli broj', 422);
                return null;
            }
        }

        return [
            'naziv'          => $naziv,
            'opis'           => $opis !== '' ? $opis : null,
            'zemlja'         => $zemlja !== '' ? $zemlja : null,
            'godina'         => $godina,
            'ocuvanost'      => $ocuvanost,
            'kataloski_broj' => $kataloski !== '' ? $kataloski : null,
            'cijena'         => (float) $cijena,
            'zaliha'         => $zaliha,
            'kategorija_id'  => $kategorijaId,
        ];
    }

    /** Mapiranje Oracle grešaka iz store/update na HTTP status. */
    private function mapirajGresku(\Throwable $e, Response $res): void
    {
        $poruka = $e->getMessage();
        if (str_contains($poruka, 'ORA-00001')) {
            $res->error('Novčanica s tim nazivom već postoji', 409);
            return;
        }
        if (str_contains($poruka, 'ORA-02291')) {
            $res->error('Kategorija s navedenim kategorija_id ne postoji', 409);
            return;
        }
        if (str_contains($poruka, 'ORA-02290')) {
            $res->error('Vrijednost krši ograničenje (provjeri cijena/godina/ocuvanost)', 422);
            return;
        }
        error_log($poruka);
        $res->error('Interna greška servera', 500);
    }
}
