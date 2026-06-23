<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Db;

final class HomeController extends BaseController
{
  public function index(Request $req, Response $res): void
  {
    $this->view($res, '<h1>banknote.hr REST API</h1><p>Radi. Vidi /health i /db-check.</p>');
  }

  public function health(Request $req, Response $res): void
  {
    $res->json(['status' => 'ok']);
  }

  public function dbCheck(Request $req, Response $res): void
  {
    try {
      $ok = Db::ping();
      $res->json(['oracle' => $ok ? 'ok' : 'fail']);
    } catch (\Throwable $e) {
      $res->json(['oracle' => 'fail', 'error' => $e->getMessage()], 500);
    }
  }
}