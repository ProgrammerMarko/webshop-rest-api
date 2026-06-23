<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Jwt;
use App\Core\Request;
use App\Core\Response;
use App\Services\Db;

final class KorisniciController extends BaseController
{
    public function register(Request $req, Response $res): void
    {
        $ct = $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
        if (!str_contains($ct, 'application/json')) {
            $res->error('Content-Type mora biti application/json', 415);
            return;
        }

        $tijelo = $req->body();
        if ($tijelo === null) {
            $res->error('Neispravan JSON', 400);
            return;
        }

        $ime     = trim($tijelo['ime']     ?? '');
        $prezime = trim($tijelo['prezime'] ?? '-');
        $email   = trim($tijelo['email']   ?? '');
        $lozinka = (string) ($tijelo['lozinka'] ?? '');

        if ($ime     === '') { $res->error('Polje ime je obavezno',     422); return; }
        if ($email   === '') { $res->error('Polje email je obavezno',   422); return; }
        if ($lozinka === '') { $res->error('Polje lozinka je obavezno', 422); return; }

        if (mb_strlen($lozinka) < 8) {
            $res->error('Lozinka mora imati najmanje 8 znakova', 422);
            return;
        }

        if ($prezime === '') {
            $prezime = '-';
        }

        $hash = password_hash($lozinka, PASSWORD_BCRYPT);

        try {
            $conn = Db::connect();

            $sql    = 'INSERT INTO korisnici (ime, prezime, email, lozinka)
                       VALUES (:ime, :prezime, :email, :lozinka)
                       RETURNING korisnik_id INTO :novi_id';
            $stmt   = oci_parse($conn, $sql);
            $noviId = 0;

            oci_bind_by_name($stmt, ':ime',     $ime);
            oci_bind_by_name($stmt, ':prezime', $prezime);
            oci_bind_by_name($stmt, ':email',   $email);
            oci_bind_by_name($stmt, ':lozinka', $hash);
            oci_bind_by_name($stmt, ':novi_id', $noviId, 10, SQLT_INT);

            if (!@oci_execute($stmt)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
            }
            oci_free_statement($stmt);

            $res->ok(['id' => (int) $noviId], 'Korisnik registriran', 201);

        } catch (\Throwable $e) {
            $poruka = $e->getMessage();
            if (str_contains($poruka, 'ORA-00001')) {
                $res->error('Email adresa već postoji', 409);
                return;
            }
            error_log($poruka);
            $res->error('Greška pri registraciji', 500);
        }
    }

    public function login(Request $req, Response $res): void
    {
        $ct = $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
        if (!str_contains($ct, 'application/json')) {
            $res->error('Content-Type mora biti application/json', 415);
            return;
        }

        $tijelo = $req->body();
        if ($tijelo === null) {
            $res->error('Neispravan JSON', 400);
            return;
        }

        $email   = trim($tijelo['email']   ?? '');
        $lozinka = (string) ($tijelo['lozinka'] ?? '');

        if ($email   === '') { $res->error('Polje email je obavezno',   422); return; }
        if ($lozinka === '') { $res->error('Polje lozinka je obavezno', 422); return; }

        try {
            $conn = Db::connect();
            $sql  = 'SELECT korisnik_id, ime, email, lozinka, uloga FROM korisnici WHERE email = :email';
            $stmt = oci_parse($conn, $sql);
            oci_bind_by_name($stmt, ':email', $email);

            if (!@oci_execute($stmt)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
            }
            $red = oci_fetch_assoc($stmt);
            oci_free_statement($stmt);

            if (!$red || !password_verify($lozinka, (string) $red['LOZINKA'])) {
                $res->error('Neispravan email ili lozinka', 401);
                return;
            }

            $token = Jwt::encode([
                'id'    => (int) $red['KORISNIK_ID'],
                'ime'   => (string) $red['IME'],
                'uloga' => (string) ($red['ULOGA'] ?? 'KUPAC'),
                'exp'   => time() + 3600,
            ]);

            $res->ok(['token' => $token], 'Uspješna prijava');

        } catch (\Throwable $e) {
            error_log($e->getMessage());
            $res->error('Greška pri prijavi', 500);
        }
    }

    public function logout(Request $req, Response $res): void
    {
        try {
            $korisnik = Auth::guard();
        } catch (\RuntimeException $e) {
            $res->error($e->getMessage(), 401);
            return;
        }

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token  = substr($header, 7);

        $expTs = (int) ($korisnik['exp'] ?? time());
        $doKad = date('Y-m-d H:i:s', $expTs);

        try {
            $conn = Db::connect();
            $sql  = 'INSERT INTO token_blacklist (token, do_kad)
                     VALUES (:token, TO_TIMESTAMP(:do_kad, \'YYYY-MM-DD HH24:MI:SS\'))';
            $stmt = oci_parse($conn, $sql);
            oci_bind_by_name($stmt, ':token',  $token);
            oci_bind_by_name($stmt, ':do_kad', $doKad);

            if (!@oci_execute($stmt)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new \RuntimeException($err['message'] ?? 'Oracle execute greška');
            }
            oci_free_statement($stmt);

            $res->ok(null, 'Odjava uspješna');
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            $res->error('Greška pri odjavi', 500);
        }
    }
}
