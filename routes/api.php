<?php
declare(strict_types=1);

use App\Core\Router;
use App\Controllers\KorisniciController;
use App\Controllers\KategorijeController;
use App\Controllers\ProizvodiController;
use App\Controllers\KosaricaController;
use App\Controllers\NarudzbeController;

return function (Router $router): void {
    // --- Autentifikacija ---
    $auth = new KorisniciController();
    $router->post('/api/auth/register', [$auth, 'register']);
    $router->post('/api/auth/login',    [$auth, 'login']);
    $router->post('/api/auth/logout',   [$auth, 'logout']);

    // --- Kategorije ---
    $kat = new KategorijeController();
    $router->get('/api/kategorije', function ($req, $res) use ($kat) {
        $req->query('id') !== null ? $kat->show($req, $res) : $kat->index($req, $res);
    });
    $router->post('/api/kategorije',   [$kat, 'store']);
    $router->put('/api/kategorije',    [$kat, 'update']);
    $router->delete('/api/kategorije', [$kat, 'destroy']);

    // --- Proizvodi (novčanice) ---
    $pro = new ProizvodiController();
    $router->get('/api/proizvodi', function ($req, $res) use ($pro) {
        $req->query('id') !== null ? $pro->show($req, $res) : $pro->index($req, $res);
    });
    $router->post('/api/proizvodi',   [$pro, 'store']);
    $router->put('/api/proizvodi',    [$pro, 'update']);
    $router->delete('/api/proizvodi', [$pro, 'destroy']);

    // --- Košarica ---
    $kos = new KosaricaController();
    $router->get('/api/kosarica',    [$kos, 'index']);
    $router->post('/api/kosarica',   [$kos, 'store']);
    $router->put('/api/kosarica',    [$kos, 'update']);    // ?proizvod_id=N
    $router->delete('/api/kosarica', [$kos, 'destroy']);   // ?proizvod_id=N

    // --- Narudžbe ---
    $nar = new NarudzbeController();
    $router->get('/api/narudzbe', function ($req, $res) use ($nar) {
        $req->query('id') !== null ? $nar->show($req, $res) : $nar->index($req, $res);
    });
    $router->post('/api/narudzbe',   [$nar, 'store']);     // checkout košarice
    $router->put('/api/narudzbe',    [$nar, 'update']);    // ?id=N (admin, status)
    $router->delete('/api/narudzbe', [$nar, 'destroy']);   // ?id=N
};
