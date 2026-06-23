<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Db;

/**
 * Kategorije novčanica (šira država-grupa, npr. Hrvatska, Jugoslavija...).
 * Veza 1:N prema proizvodima.
 */
final class KategorijeController extends BaseController
{
    private const SORT_WHITELIST = ['id', 'naziv'];

    /** GET /api/kategorije - javna paginirana lista s pretragom i sortiranjem. */
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

        try {
            $conn = Db::connect();

            $sqlBroj  = 'SELECT COUNT(*) AS ukupno FROM kategorije WHERE UPPER(naziv) LIKE UPPER(:q)';
            $stmtBroj = oci_parse($conn, $sqlBroj);
            oci_bind_by_name($stmtBroj, ':q', $qParam);
            oci_execute($stmtBroj);
            $redBroj = oci_fetch_assoc($stmtBroj);
            oci_free_statement($stmtBroj);
            $ukupno = (int) $redBroj['UKUPNO'];

            $sql  = 'SELECT k.id, k.naziv, k.opis,
                            (SELECT COUNT(*) FROM proizvodi p WHERE p.kategorija_id = k.id) AS broj_proizvoda
                     FROM   kategorije k
                     WHERE  UPPER(k.naziv) LIKE UPPER(:q)
                     ORDER BY k.' . $sort . ' ' . $smjer . '
                     OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY';
            $stmt = oci_parse($conn, $sql);
            oci_bind_by_name($stmt, ':q',      $qParam);
            oci_bind_by_name($stmt, ':offset', $offset);
            oci_bind_by_name($stmt, ':limit',  $poStranici);
            oci_execute($stmt);

            $kategorije = [];
            while ($red = oci_fetch_assoc($stmt)) {
                $kategorije[] = [
                    'id'             => (int) $red['ID'],
                    'naziv'          => (string) $red['NAZIV'],
                    'opis'           => $red['OPIS'] !== null ? (string) $red['OPIS'] : null,
                    'broj_proizvoda' => (int) $red['BROJ_PROIZVODA'],
                ];
            }
            oci_free_statement($stmt);

            $res->ok([
                'kategorije' => $kategorije,
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

    /** GET /api/kategorije?id=N - javni prikaz jedne kategorije s brojem proizvoda. */
    public function show(Request $req, Response $res): void
    {
        $id = $this->pozitivanId($req->query('id'), $res);
        if ($id === null) {
            return;
        }

        try {
            $conn = Db::connect();
            $sql  = 'SELECT k.id, k.naziv, k.opis,
                            (SELECT COUNT(*) FROM proizvodi p WHERE p.kategorija_id = k.id) AS broj_proizvoda
                     FROM   kategorije k
                     WHERE  k.id = :id';
            $stmt = oci_parse($conn, $sql);
            oci_bind_by_name($stmt, ':id', $id);
            oci_execute($stmt);
            $red = oci_fetch_assoc($stmt);
            oci_free_statement($stmt);

            if (!$red) {
                $res->error('Kategorija nije pronađena', 404);
                return;
            }

            $res->ok([
                'id'             => (int) $red['ID'],
                'naziv'          => (string) $red['NAZIV'],
                'opis'           => $red['OPIS'] !== null ? (string) $red['OPIS'] : null,
                'broj_proizvoda' => (int) $red['BROJ_PROIZVODA'],
            ]);
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            $res->error('Interna greška servera', 500);
        }
    }

    /** POST /api/kategorije - kreiranje (samo admin). */
    public function store(Request $req, Response $res): void
    {
        if ($this->traziAdmina($res) === null) {
            return;
        }

        $tijelo = $this->jsonTijelo($req, $res);
        if ($tijelo === null) {
            return;
        }

        $naziv = trim((string) ($tijelo['naziv'] ?? ''));
        $opis  = trim((string) ($tijelo['opis'] ?? ''));

        if ($naziv === '') {
            $res->error('Polje naziv je obavezno', 422);
            return;
        }
        if (mb_strlen($naziv) > 100) {
            $res->error('Polje naziv ne smije biti dulje od 100 znakova', 422);
            return;
        }
        if (mb_strlen($opis) > 500) {
            $res->error('Polje opis ne smije biti dulje od 500 znakova', 422);
            return;
        }

        try {
            $conn   = Db::connect();
            $opisDb = $opis !== '' ? $opis : null;

            $sql    = 'INSERT INTO kategorije (naziv, opis)
                       VALUES (:naziv, :opis)
                       RETURNING id INTO :novi_id';
            $stmt   = oci_parse($conn, $sql);
            $noviId = 0;
            oci_bind_by_name($stmt, ':naziv',   $naziv);
            oci_bind_by_name($stmt, ':opis',    $opisDb);
            oci_bind_by_name($stmt, ':novi_id', $noviId, 10, SQLT_INT);

            if (!@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
            }
            oci_free_statement($stmt);
            oci_commit($conn);

            $res->ok(['id' => (int) $noviId], 'Kategorija kreirana', 201);
        } catch (\Throwable $e) {
            if (isset($conn)) {
                oci_rollback($conn);
            }
            if (str_contains($e->getMessage(), 'ORA-00001')) {
                $res->error('Kategorija s tim nazivom već postoji', 409);
                return;
            }
            error_log($e->getMessage());
            $res->error('Interna greška servera', 500);
        }
    }

    /** PUT /api/kategorije?id=N - ažuriranje (samo admin). */
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

        $naziv = trim((string) ($tijelo['naziv'] ?? ''));
        $opis  = trim((string) ($tijelo['opis'] ?? ''));

        if ($naziv === '') {
            $res->error('Polje naziv je obavezno', 422);
            return;
        }
        if (mb_strlen($naziv) > 100) {
            $res->error('Polje naziv ne smije biti dulje od 100 znakova', 422);
            return;
        }
        if (mb_strlen($opis) > 500) {
            $res->error('Polje opis ne smije biti dulje od 500 znakova', 422);
            return;
        }

        try {
            $conn   = Db::connect();
            $opisDb = $opis !== '' ? $opis : null;

            $sql  = 'UPDATE kategorije SET naziv = :naziv, opis = :opis WHERE id = :id';
            $stmt = oci_parse($conn, $sql);
            oci_bind_by_name($stmt, ':naziv', $naziv);
            oci_bind_by_name($stmt, ':opis',  $opisDb);
            oci_bind_by_name($stmt, ':id',    $id);

            if (!@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
            }
            $azurirano = oci_num_rows($stmt);
            oci_free_statement($stmt);

            if ($azurirano === 0) {
                oci_rollback($conn);
                $res->error('Kategorija nije pronađena', 404);
                return;
            }
            oci_commit($conn);
            $res->ok(['id' => (int) $id], 'Kategorija ažurirana');
        } catch (\Throwable $e) {
            if (isset($conn)) {
                oci_rollback($conn);
            }
            if (str_contains($e->getMessage(), 'ORA-00001')) {
                $res->error('Kategorija s tim nazivom već postoji', 409);
                return;
            }
            error_log($e->getMessage());
            $res->error('Interna greška servera', 500);
        }
    }

    /** DELETE /api/kategorije?id=N - brisanje (samo admin). */
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
            $sql  = 'DELETE FROM kategorije WHERE id = :id';
            $stmt = oci_parse($conn, $sql);
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
                $res->error('Kategorija nije pronađena', 404);
                return;
            }
            oci_commit($conn);
            $res->ok(null, 'Kategorija obrisana');
        } catch (\Throwable $e) {
            if (isset($conn)) {
                oci_rollback($conn);
            }
            if (str_contains($e->getMessage(), 'ORA-02292')) {
                $res->error('Kategorija se ne može obrisati jer ima povezane proizvode', 409);
                return;
            }
            error_log($e->getMessage());
            $res->error('Interna greška servera', 500);
        }
    }
}
