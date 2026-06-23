# banknote.hr - REST API

**Autor:** Marko (mspoljarec) | **Kolegij:** Poslužiteljske Web Aplikacije

Praktični projekt iz kolegija **Poslužiteljske Web Aplikacije**.
REST API za web trgovinu **kolekcionarskim novčanicama** (numizmatika), izrađen na
vlastitom mini-MVC frameworku: **PHP 8.1+ + Oracle (OCI8) + Apache/PHP-FPM**, s JWT
autentifikacijom i bcrypt hashiranjem lozinki.

Domena pokriva katalog (kategorije -> novčanice), korisnike s ulogama (**admin/kupac**),
**košaricu** i **narudžbe** (checkout kao transakcija nad više tablica).

## Tehnologije

- PHP 8.1+ (`declare(strict_types=1)`), bez vanjskih frameworka
- Oracle Database preko OCI8 ekstenzije (raw SQL + bind varijable, transakcije)
- Apache + `mod_rewrite` (front controller `public/index.php`)
- Composer PSR-4 autoload (`App\` -> `app/`)
- JWT (custom HS256), `password_hash`/`password_verify` (bcrypt)

## Brzi početak

```bash
# 1. Ovisnosti (samo PSR-4 autoloader)
composer install        # ili: composer dump-autoload

# 2. Konfiguracija
cp .env.example .env     # popuni DB kredencijale i JWT_SECRET
#   JWT_SECRET generiraj s:
php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"

# 3. Baza (u Oracle alatu / SQL*Plus / SQL Developer)
@database/schema.sql
@database/seed.sql

# 4. Posluži preko Apache + PHP-FPM (document root = public/)
#    Prilagodi RewriteBase u public/.htaccess i APP_URL u .env.
```

Zatim u Postmanu (ili `curl`):

```bash
# registracija + prijava
curl -X POST .../api/auth/register -H 'Content-Type: application/json' \
     -d '{"ime":"Admin","email":"admin@banknote.hr","lozinka":"tajna123"}'

# promoviraj u admina (jednokratno, u bazi):
#   UPDATE korisnici SET uloga='ADMIN' WHERE email='admin@banknote.hr'; COMMIT;

curl -X POST .../api/auth/login -H 'Content-Type: application/json' \
     -d '{"email":"admin@banknote.hr","lozinka":"tajna123"}'   # -> {"token": "..."}
```

Detaljan opis arhitekture, ER model, popis svih endpointa i scenarije testiranja
vidi u [`docs/projekt-banknote.md`](docs/projekt-banknote.md).

## Demo u Postmanu

Glavni nacin demonstracije je Postman kolekcija
[`docs/postman/banknote.postman_collection.json`](docs/postman/banknote.postman_collection.json)
(7 foldera, 32 zahtjeva, poredano po tijeku; svaki zahtjev ima test pa pokazuje zeleno/crveno),
uz dva environmenta u `docs/postman/`:
- **banknote.hr - php server (test)** -> `base_url = http://127.0.0.1:8765` (lokalni `php -S`)
- **banknote.hr - Apache (demo)** -> `base_url = http://localhost/mspoljarec/banknote-projekt/public`

> **Server mora biti pokrenut**, inace Postman javlja `ECONNREFUSED`. Najbrze za test:
> ```bash
> cd /home/marko/vub/banknote-projekt
> php -S 127.0.0.1:8765 -t public public/index.php
> ```
> i u Postmanu odaberi environment **"php server (test)"**. Za demo preko Apache
> (rsync na serving putanju) odaberi **"Apache (demo)"**.

**Demo korisnici su u seedu** (nista se ne mora rucno promovirati):
`admin@banknote.hr` / `tajna123` (ADMIN) i `kupac@banknote.hr` / `tajna123` (KUPAC).

1. U Postmanu **Import** -> ubaci kolekciju i oba environmenta.
2. Gore desno odaberi environment (`php server` za lokalni test, `Apache` za demo).
3. Klikci foldere redom **1 -> 7** (ili "Run collection"). Prijave spremaju tokene
   (`admin_token`, `kupac_token`), a create/checkout zahtjevi spremaju ID-eve.

Kolekcija je napravljena da se moze pokretati **vise puta zaredom bez gresaka**: kreiranja koriste
jedinstvene nazive (nema "vec postoji"), folderi 2/4/5 sami pociste i vrate zalihu. Folder
**6. Greske** namjerno pokazuje 4xx (401/403/404/409/422/415) - to su zeleni testovi.

> Dobijes li `403` na admin ruti: prijavljen si kao korisnik koji nije ADMIN. Koristi
> `1.2 Prijava administratora` (`admin@banknote.hr` / `tajna123`) iz seeda.

Folder **5. Integritet zalihe (unikat)** je glavni adut: pokazuje da se unikat (`zaliha=1`)
ne moze prodati dvaput (drugi pokusaj -> `409`).

## Struktura

```
app/Controllers/   KorisniciController, KategorijeController, ProizvodiController,
                   KosaricaController, NarudzbeController, BaseController (auth/JSON helperi)
app/Core/          Router, Request, Response, Auth, Jwt, Env
app/Services/      Db (OCI8 singleton)
config/            app.php, database.php, security.php
routes/            web.php (health), api.php (svi API endpointi)
database/          schema.sql, seed.sql
public/            index.php (front controller), .htaccess
docs/              projekt-banknote.md, postman/
```
