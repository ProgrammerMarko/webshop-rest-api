<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

abstract class BaseController
{
  /** Vrijednost uloge u tablici korisnici (velika slova kao u shemi: KUPAC, ADMIN, MODERATOR). */
  protected const ULOGA_ADMIN = 'ADMIN';

  protected function view(Response $res, string $html, int $status = 200): void
  {
    $res->html($html, $status);
  }

  /** Je li prijavljeni korisnik administrator (usporedba neovisna o velikim/malim slovima). */
  protected function jeAdmin(array $korisnik): bool
  {
    return strtoupper((string) ($korisnik['uloga'] ?? '')) === self::ULOGA_ADMIN;
  }

  /**
   * Provjeri JWT iz Authorization headera.
   * Vraća podatke o korisniku (id, ime, uloga, exp) ili null uz poslan 401.
   */
  protected function traziKorisnika(Response $res): ?array
  {
    try {
      return Auth::guard();
    } catch (\RuntimeException $e) {
      $res->error($e->getMessage(), 401);
      return null;
    }
  }

  /**
   * Kao traziKorisnika(), ali dodatno zahtijeva ulogu 'admin'.
   * 401 ako nije prijavljen, 403 ako je prijavljen ali nije admin.
   */
  protected function traziAdmina(Response $res): ?array
  {
    $korisnik = $this->traziKorisnika($res);
    if ($korisnik === null) {
      return null;
    }
    if (!$this->jeAdmin($korisnik)) {
      $res->error('Pristup dozvoljen samo administratoru', 403);
      return null;
    }
    return $korisnik;
  }

  /**
   * Provjeri Content-Type i parsiraj JSON tijelo.
   * Vraća polje ili null uz poslan 415 (krivi Content-Type) / 400 (neispravan JSON).
   */
  protected function jsonTijelo(Request $req, Response $res): ?array
  {
    $ct = $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
    if (!str_contains($ct, 'application/json')) {
      $res->error('Content-Type mora biti application/json', 415);
      return null;
    }

    $tijelo = $req->body();
    if ($tijelo === null) {
      $res->error('Tijelo zahtjeva nije validan JSON', 400);
      return null;
    }

    return $tijelo;
  }

  /**
   * Validacija pozitivnog cijelog broja iz query parametra (npr. ?id=N).
   * Vraća int ili null uz poslan 422.
   */
  protected function pozitivanId(mixed $vrijednost, Response $res, string $naziv = 'id'): ?int
  {
    if ($vrijednost === null) {
      $res->error("Parametar {$naziv} je obavezan", 422);
      return null;
    }
    if (!ctype_digit((string) $vrijednost) || (int) $vrijednost < 1) {
      $res->error("Parametar {$naziv} mora biti pozitivan cijeli broj", 422);
      return null;
    }
    return (int) $vrijednost;
  }
}
