<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Db;

/**
 * Košarica po korisniku (tablica stavke_kosarice).
 * Sve rute zahtijevaju prijavu; korisnik vidi i mijenja isključivo svoju košaricu.
 */
final class KosaricaController extends BaseController
{
    /** GET /api/kosarica - stavke trenutnog korisnika + ukupan iznos. */
    public function index(Request $req, Response $res): void
    {
        $korisnik = $this->traziKorisnika($res);
        if ($korisnik === null) {
            return;
        }
        $korisnikId = (int) $korisnik['id'];

        try {
            $conn = Db::connect();
            $sql  = 'SELECT sk.proizvod_id, sk.kolicina,
                            p.naziv, p.cijena, p.zaliha
                     FROM   stavke_kosarice sk
                     JOIN   proizvodi p ON sk.proizvod_id = p.id
                     WHERE  sk.korisnik_id = :kid
                     ORDER BY p.naziv';
            $stmt = oci_parse($conn, $sql);
            oci_bind_by_name($stmt, ':kid', $korisnikId);
            oci_execute($stmt);

            $stavke = [];
            $ukupno = 0.0;
            while ($red = oci_fetch_assoc($stmt)) {
                $cijena   = (float) $red['CIJENA'];
                $kolicina = (int) $red['KOLICINA'];
                $iznos    = $cijena * $kolicina;
                $ukupno  += $iznos;

                $stavke[] = [
                    'proizvod_id' => (int) $red['PROIZVOD_ID'],
                    'naziv'       => (string) $red['NAZIV'],
                    'cijena'      => $cijena,
                    'kolicina'    => $kolicina,
                    'zaliha'      => (int) $red['ZALIHA'],
                    'iznos'       => round($iznos, 2),
                ];
            }
            oci_free_statement($stmt);

            $res->ok([
                'stavke' => $stavke,
                'ukupno' => round($ukupno, 2),
            ]);
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            $res->error('Interna greška servera', 500);
        }
    }

    /** POST /api/kosarica - dodaj stavku {proizvod_id, kolicina}. */
    public function store(Request $req, Response $res): void
    {
        $korisnik = $this->traziKorisnika($res);
        if ($korisnik === null) {
            return;
        }
        $tijelo = $this->jsonTijelo($req, $res);
        if ($tijelo === null) {
            return;
        }
        $korisnikId = (int) $korisnik['id'];

        $proizvodId = $tijelo['proizvod_id'] ?? null;
        if (!is_int($proizvodId) || $proizvodId < 1) {
            $res->error('Polje proizvod_id mora biti pozitivan cijeli broj', 422);
            return;
        }
        $kolicina = $tijelo['kolicina'] ?? null;
        if (!is_int($kolicina) || $kolicina < 1) {
            $res->error('Polje kolicina mora biti cijeli broj >= 1', 422);
            return;
        }

        try {
            $conn = Db::connect();

            // Provjeri postoji li proizvod i ima li dovoljno na zalihi
            $stmtP = oci_parse($conn, 'SELECT zaliha FROM proizvodi WHERE id = :pid');
            oci_bind_by_name($stmtP, ':pid', $proizvodId);
            oci_execute($stmtP);
            $redP = oci_fetch_assoc($stmtP);
            oci_free_statement($stmtP);

            if (!$redP) {
                $res->error('Novčanica nije pronađena', 404);
                return;
            }
            if ($kolicina > (int) $redP['ZALIHA']) {
                $res->error('Nema dovoljno na zalihi (dostupno: ' . (int) $redP['ZALIHA'] . ')', 409);
                return;
            }

            $sql  = 'INSERT INTO stavke_kosarice (korisnik_id, proizvod_id, kolicina)
                     VALUES (:kid, :pid, :kol)';
            $stmt = oci_parse($conn, $sql);
            oci_bind_by_name($stmt, ':kid', $korisnikId);
            oci_bind_by_name($stmt, ':pid', $proizvodId);
            oci_bind_by_name($stmt, ':kol', $kolicina);

            if (!@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
            }
            oci_free_statement($stmt);
            oci_commit($conn);

            $res->ok(null, 'Stavka dodana u košaricu', 201);
        } catch (\Throwable $e) {
            if (isset($conn)) {
                oci_rollback($conn);
            }
            if (str_contains($e->getMessage(), 'ORA-00001')) {
                $res->error('Novčanica je već u košarici (koristi PUT za promjenu količine)', 409);
                return;
            }
            error_log($e->getMessage());
            $res->error('Interna greška servera', 500);
        }
    }

    /** PUT /api/kosarica?proizvod_id=N - promijeni količinu stavke. */
    public function update(Request $req, Response $res): void
    {
        $korisnik = $this->traziKorisnika($res);
        if ($korisnik === null) {
            return;
        }
        $proizvodId = $this->pozitivanId($req->query('proizvod_id'), $res, 'proizvod_id');
        if ($proizvodId === null) {
            return;
        }
        $tijelo = $this->jsonTijelo($req, $res);
        if ($tijelo === null) {
            return;
        }
        $korisnikId = (int) $korisnik['id'];

        $kolicina = $tijelo['kolicina'] ?? null;
        if (!is_int($kolicina) || $kolicina < 1) {
            $res->error('Polje kolicina mora biti cijeli broj >= 1', 422);
            return;
        }

        try {
            $conn = Db::connect();

            // Provjera zalihe
            $stmtP = oci_parse($conn, 'SELECT zaliha FROM proizvodi WHERE id = :pid');
            oci_bind_by_name($stmtP, ':pid', $proizvodId);
            oci_execute($stmtP);
            $redP = oci_fetch_assoc($stmtP);
            oci_free_statement($stmtP);
            if ($redP && $kolicina > (int) $redP['ZALIHA']) {
                $res->error('Nema dovoljno na zalihi (dostupno: ' . (int) $redP['ZALIHA'] . ')', 409);
                return;
            }

            $sql  = 'UPDATE stavke_kosarice SET kolicina = :kol
                     WHERE korisnik_id = :kid AND proizvod_id = :pid';
            $stmt = oci_parse($conn, $sql);
            oci_bind_by_name($stmt, ':kol', $kolicina);
            oci_bind_by_name($stmt, ':kid', $korisnikId);
            oci_bind_by_name($stmt, ':pid', $proizvodId);

            if (!@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
            }
            $azurirano = oci_num_rows($stmt);
            oci_free_statement($stmt);

            if ($azurirano === 0) {
                oci_rollback($conn);
                $res->error('Stavka nije u košarici', 404);
                return;
            }
            oci_commit($conn);
            $res->ok(null, 'Količina ažurirana');
        } catch (\Throwable $e) {
            if (isset($conn)) {
                oci_rollback($conn);
            }
            error_log($e->getMessage());
            $res->error('Interna greška servera', 500);
        }
    }

    /** DELETE /api/kosarica?proizvod_id=N - makni stavku iz košarice. */
    public function destroy(Request $req, Response $res): void
    {
        $korisnik = $this->traziKorisnika($res);
        if ($korisnik === null) {
            return;
        }
        $proizvodId = $this->pozitivanId($req->query('proizvod_id'), $res, 'proizvod_id');
        if ($proizvodId === null) {
            return;
        }
        $korisnikId = (int) $korisnik['id'];

        try {
            $conn = Db::connect();
            $sql  = 'DELETE FROM stavke_kosarice WHERE korisnik_id = :kid AND proizvod_id = :pid';
            $stmt = oci_parse($conn, $sql);
            oci_bind_by_name($stmt, ':kid', $korisnikId);
            oci_bind_by_name($stmt, ':pid', $proizvodId);

            if (!@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
            }
            $obrisano = oci_num_rows($stmt);
            oci_free_statement($stmt);

            if ($obrisano === 0) {
                oci_rollback($conn);
                $res->error('Stavka nije u košarici', 404);
                return;
            }
            oci_commit($conn);
            $res->ok(null, 'Stavka uklonjena iz košarice');
        } catch (\Throwable $e) {
            if (isset($conn)) {
                oci_rollback($conn);
            }
            error_log($e->getMessage());
            $res->error('Interna greška servera', 500);
        }
    }
}
