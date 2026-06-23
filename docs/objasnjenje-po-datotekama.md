# Objašnjenje projekta — datoteka po datoteci (+ PHP sintaksa)

> Čitaj redom. Prvo poglavlje **0** objašnjava PHP/OCI8 sintaksu koja se **stalno ponavlja** —
> nauči to jednom i onda u svakoj datoteci prepoznaješ iste konstrukte. Datoteke koje rade
> isto (npr. svi CRUD kontroleri) objašnjene su jednom u cijelosti (`ProizvodiController`),
> a za ostale su navedene samo **razlike**.

---

## 0. PHP + OCI8 sintaksa (referenca — objašnjeno jednom)

### Osnovni PHP konstrukti

| Konstrukt | Značenje |
|---|---|
| `<?php` | početak PHP koda u datoteci |
| `declare(strict_types=1);` | strogi tipovi — ako funkcija traži `int`, ne smiješ poslati `"5"` kao string |
| `namespace App\Core;` | "prezime" datoteke/klase; mora odgovarati mapi (`app/Core/`) |
| `use App\Core\Response;` | uvezi klasu iz drugog namespacea da je zoveš kraće (`Response` umjesto pune putanje) |
| `final class X` | klasa koju nitko ne smije naslijediti |
| `abstract class X` | "nedovršena" klasa — služi samo da se iz nje nasljeđuje |
| `class Y extends X` | Y nasljeđuje sva svojstva/metode iz X |
| `public / private / protected` | tko smije pristupiti: svi / samo ova klasa / klasa + naslijeđene |
| `function ime(): void` | `: void` znači "ne vraća ništa"; `: ?array` znači "vraća polje ili `null`" |
| `mixed` | tip "bilo što" |
| `$this->x` | pristup svojstvu/metodi **ovog objekta** (`->` = nad objektom) |
| `Klasa::metoda()` | poziv **statičke** metode bez objekta (`::` = nad klasom) |
| `self::KONSTANTA` | pristup konstanti/statici unutar iste klase |

### Operatori i kratice koje se često vide

| Zapis | Što radi |
|---|---|
| `$x ?? 'default'` | ako je `$x` `null` ili ne postoji → uzmi `'default'` ("null coalescing") |
| `$uvjet ? 'a' : 'b'` | kratki if/else (ternarni): ako uvjet → `'a'`, inače `'b'` |
| `getenv('X') ?: 'def'` | ako je lijevo "prazno/false" → uzmi desno (kraći ternarni) |
| `(int) $x`, `(float) $x`, `(string) $x` | prisilna pretvorba tipa (casting) |
| `[$k, $v] = explode('=', $line, 2)` | razdvoji string na 2 dijela i **odmah** spremi u dvije varijable (destrukturiranje) |
| `fn($c, $i) => $c && $i['ok']` | "arrow funkcija" — kratka anonimna funkcija (vidi se u health.php) |
| `"Bok $ime"` | dvostruki navodnici umeću varijablu; `'Bok $ime'` (jednostruki) NE umeću |
| `@oci_execute(...)` | `@` ispred funkcije **priguši** PHP upozorenje (grešku hvatamo sami ručno) |
| `str_contains`, `str_starts_with`, `trim`, `mb_strlen` | string funkcije: sadrži / počinje s / makni razmake / duljina (Unicode) |

### OCI8 — kako se priča s Oracle bazom (PONAVLJA SE U SVIM KONTROLERIMA)

OCI8 je PHP ekstenzija za Oracle. Svaki upit ide kroz **isti niz koraka**:

```php
$conn = Db::connect();                          // 1. veza na bazu
$stmt = oci_parse($conn, 'SELECT ... WHERE id = :id');  // 2. pripremi SQL (s "rupom" :id)
oci_bind_by_name($stmt, ':id', $id);            // 3. sigurno ubaci vrijednost u :id (anti SQL-injection)
oci_execute($stmt);                             // 4. izvrši
$red = oci_fetch_assoc($stmt);                  // 5. dohvati redak kao asoc. polje ['ID'=>.., 'NAZIV'=>..]
oci_free_statement($stmt);                      // 6. oslobodi resurs
```

Bitno za zapamtiti:
- **`:ime`** u SQL-u je **bind varijabla** ("rupa"). Vrijednosti se NIKAD ne lijepe u string —
  uvijek idu kroz `oci_bind_by_name`. To je obrana od **SQL injectiona**.
- Oracle vraća imena stupaca **VELIKIM SLOVIMA** — zato u kodu vidiš `$red['NAZIV']`, `$red['ID']`.
- **Transakcije:** ako `oci_execute($stmt, OCI_NO_AUTO_COMMIT)` — promjena se NE sprema odmah;
  treba ručno `oci_commit($conn)` (potvrdi) ili `oci_rollback($conn)` (poništi sve). Bez te
  zastavice OCI8 commita svaku naredbu automatski.
- `oci_num_rows($stmt)` — koliko je redaka promijenjeno (za UPDATE/DELETE → znamo je li nešto pogođeno).
- `RETURNING id INTO :novi_id` — Oracle vrati ID upravo ubačenog retka u bind varijablu.

> Sad kad ovo znaš, u kontrolerima gledaš samo **koji SQL** se izvršava i **zašto** — mehanika
> (`parse/bind/execute/fetch/free`) je svugdje ista.

---

# A. Bootstrap i konfiguracija (kako se aplikacija uopće pokrene)

## `composer.json`
**Što:** opis projekta + **autoload** pravilo.
**Kako:** dio `"psr-4": { "App\\": "app/" }` znači: kad u kodu napišeš `use App\Core\Router;`,
Composer automatski učita datoteku `app/Core/Router.php`. Zato nigdje nema ručnog
`require 'Router.php'` — namespace `App\...` se preslikava na mapu `app/...`. `require: php >=8.1`
je jedini "uvjet" (projekt nema vanjskih biblioteka).

## `.env.example`
**Što:** predložak tajni/postavki (kopira se u `.env`, koji se NE commita).
**Kako:** obične `KLJUC=vrijednost` linije: `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SERVICE`
(Oracle), te `JWT_SECRET` (tajni ključ za potpis tokena). `APP_URL` ima `{{username}}` koji
zamijeniš svojim. Poanta: **tajne su izvan koda**, u datoteci koja se ne dijeli.

## `config/database.php`  (pun primjer config obrasca)
**Što:** vrati polje s postavkama baze.
**Kako:** datoteka je samo `return [ ... ];` — kad je netko `require`-a, dobije to polje.
Svaka vrijednost se čita ovako:
```php
'host' => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost',
```
Čitaj zdesna: probaj `$_ENV['DB_HOST']`; ako toga nema (`??`) probaj `getenv(...)`; ako je i to
prazno (`?:`) → `'localhost'`. Tako config radi i ako `.env` fali (ima razumne defaulte).

**`config/app.php` i `config/security.php` — razlike:** isti obrazac (`return [...]` s
`getenv() ?: default`), samo druge vrijednosti (`app.php`: ime/okolina/URL; `security.php`:
postavke kolačića sesije). `security.php` se u ovom API-ju realno ne koristi (API ne radi sa
sesijama nego s JWT-om) — tu je kao ostatak skeletona s labosa.

## `public/.htaccess`
**Što:** Apache pravilo da **sve** ide na `index.php` (front controller).
**Kako:**
```apache
RewriteCond %{REQUEST_FILENAME} -f [OR]   # ako traženo JE postojeća datoteka...
RewriteCond %{REQUEST_FILENAME} -d        # ...ili postojeća mapa...
RewriteRule ^ - [L]                       # ...posluži je direktno (npr. health.php)
RewriteRule ^ index.php [L]               # inače: prepiši na index.php
```
`RewriteBase /mspoljarec/banknote-projekt/public/` govori Apacheu putanju projekta (to
prilagodiš svom serveru). Rezultat: `/api/proizvodi`, `/api/narudzbe`... sve fizički ide u
`index.php`, koji onda odlučuje što napraviti.

## `public/index.php`  ⭐ (ulazna točka — ovdje SVE počinje)
**Što:** "front controller" — prima svaki zahtjev i pokreće aplikaciju.
**Kako (red po red):**
```php
require __DIR__ . '/../vendor/autoload.php';  // uključi Composer autoload (da use radi)
Env::load(__DIR__ . '/../.env');              // učitaj .env u okolinu
$router = new Router();                        // napravi router
$routes = require __DIR__ . '/../routes/web.php';  // datoteka VRAĆA funkciju...
$routes($router);                              // ...koju odmah pozoveš i ona registrira rute
$routes = require __DIR__ . '/../routes/api.php';
$routes($router);
$req = new Request(); $res = new Response();
$router->dispatch($req, $res);                 // nađi pravu rutu i izvrši je
```
Ključ: `routes/*.php` vraćaju **funkciju**, pa `$routes($router)` znači "pozovi tu funkciju i
predaj joj router da u njega upiše rute". `__DIR__` je mapa trenutne datoteke (apsolutna putanja).

---

# B. Core jezgra (redom kako sudjeluju u jednom zahtjevu)

## `app/Core/Env.php`
**Što:** učita `.env` i ubaci varijable u okolinu.
**Kako:** `file($path, ...)` pročita datoteku u polje linija; petlja preskoči prazne i `#`
komentare; `[$key, $value] = explode('=', $line, 2)` razdvoji `KLJUC=vrijednost`; pa
`putenv("$key=$value")` + upis u `$_ENV`/`$_SERVER` da kasnije `getenv()` to vidi. (Isti
mini-parser ponavlja se i u `health.php`.)

## `app/Core/Request.php`
**Što:** "omotač" oko podataka HTTP zahtjeva (čitaš ih preko metoda umjesto direktno).
**Kako:**
- `method()` → vrati HTTP metodu iz `$_SERVER['REQUEST_METHOD']` (GET/POST/...).
- `path()` → uzme putanju iz URL-a (`parse_url(..., PHP_URL_PATH)`) i odreže sve do `/public`
  (da `/.../public/api/proizvodi` postane `/api/proizvodi` — da rute rade i u podmapi).
- `query('id')` → `$_GET['id']` (parametri iza `?`).
- `body()` → pročita sirovo tijelo (`php://input`) i `json_decode(..., true)` u asoc. polje;
  ako JSON nije valjan → vrati `null`. (`true` znači "daj polje, ne objekt".)

## `app/Core/Response.php`
**Što:** slaže HTTP odgovor (status + zaglavlja + tijelo).
**Kako:** `json($data, $status)` postavi kod (`http_response_code`), zaglavlje
`Content-Type: application/json` i ispiše `json_encode(...)`. Dvije "lijepe" metode daju
**jedinstveni oblik** svih odgovora:
```php
ok($data, $message, $status=200)   → {"success":true,  "data":..., "message":...}
error($message, $status=400, $data)→ {"success":false, "data":..., "message":...}
```
`text()`/`html()` su za ne-JSON odgovore (koristi ih HomeController). `JSON_UNESCAPED_UNICODE`
znači da se "č, ž, š" ispišu normalno, a ne kao `\uXXXX`.

## `app/Core/Router.php`
**Što:** pamti rute i bira koju funkciju pozvati.
**Kako:** drži polje `$routes['GET']['/api/proizvodi'] = handler`. Metode `get/post/put/delete`
samo upisuju handler pod tu metodu+putanju. `dispatch()` pogleda `method()` + `path()` iz
Requesta, nađe handler i pozove `$handler($req, $res)`. Ako ga nema → `404`. **Bitno:** putanja
mora biti **točna** (nema `/proizvodi/{id}`), pa se ID šalje kao `?id=N` (vidi `api.php`).

## `app/Core/Jwt.php`  ⭐
**Što:** stvara i provjerava JWT token (potpis tajnim ključem).
**Kako:** token = `header.payload.signature`, svaki dio "base64url" kodiran (`b64url()`).
- `encode($payload)` → složi header `{"alg":"HS256"}`, payload (tvoji podaci), i potpis
  `hash_hmac('sha256', "$header.$payload", TAJNA, true)`. Vrati spojeno s točkama.
- `decode($token)` → razdvoji na 3 dijela; **ponovno izračunaj** potpis i usporedi s onim u
  tokenu preko `hash_equals` (otporno na "timing" napad); ako se ne poklapa → greška. Zatim
  dekodira payload i provjeri `exp` (ako je `exp < time()` → "token istekao").

Poanta: payload je samo **kodiran, ne šifriran** (svatko ga može pročitati), ali ga **nitko ne
može krivotvoriti** bez `JWT_SECRET`, jer bi potpis bio pogrešan.

## `app/Core/Auth.php`  ⭐
**Što:** čuvar — provjeri token na zaštićenim rutama, vrati podatke o korisniku.
**Kako:** pročita zaglavlje `Authorization`; ako ne počinje s `Bearer ` → greška. Uzme token
(`substr($header, 7)` makne "Bearer "), pozove `Jwt::decode` (potpis+istek), pa provjeri je li
token na **`token_blacklist`** (odjavljen) jednim `SELECT COUNT(*)`. Ako je sve OK → vrati
payload (`id`, `ime`, `uloga`, `exp`). Greške baca kao `RuntimeException` (kontroleri ih hvataju).

---

# C. Services

## `app/Services/Db.php`
**Što:** jedna (singleton) veza na Oracle.
**Kako:** `connect()` — ako veza već postoji vrati istu (`if (self::$conn) return self::$conn;`),
inače učita `config/database.php`, složi Oracle **DSN** string
`(DESCRIPTION=(ADDRESS=...HOST..PORT..)(CONNECT_DATA=(SERVICE_NAME=..)))` i pozove
`oci_connect($user, $pass, $dsn, $charset)`. Ako veza padne → baci grešku s OCI porukom.
`ping()` — pokuša `SELECT 1 FROM dual` (Oracleova "prazna" tablica) da provjeri radi li baza.
Singleton znači: spojiš se jednom po zahtjevu, svi kontroleri dijele tu vezu.

---

# D. Routes

## `routes/web.php`
**Što:** rute koje nisu API (dijagnostika).
**Kako:** vrati funkciju koja u router upiše `GET /`, `GET /health`, `GET /db-check` → metode
`HomeController`-a. Sintaksa `[$c, 'index']` znači "pozovi metodu `index` na objektu `$c`".

## `routes/api.php`  ⭐
**Što:** svi API endpointi.
**Kako:** napravi po jedan kontroler i registrira rute. Dvije stvari za uočiti:
- **Auth rute** idu direktno na metode: `$router->post('/api/auth/login', [$auth, 'login'])`.
- **`?id=` trik** za GET liste vs. jedan element:
```php
$router->get('/api/proizvodi', function ($req, $res) use ($pro) {
    $req->query('id') !== null ? $pro->show($req, $res) : $pro->index($req, $res);
});
```
Pošto router ne podržava `/proizvodi/{id}`, ista ruta zove `show()` ako postoji `?id=`, inače
`index()` (lista). `use ($pro)` uvuče vanjsku varijablu u anonimnu funkciju. PUT/DELETE za
proizvode/kategorije/narudžbe također koriste `?id=`, a košarica `?proizvod_id=`.

---

# E. Controllers (poslovna logika)

## `app/Controllers/BaseController.php`  ⭐ (zajednički alat svih kontrolera)
**Što:** `abstract` klasa s pomoćnim metodama koje svi kontroleri nasljeđuju — da se kod ne ponavlja.
**Kako:**
- `traziKorisnika($res)` → pozove `Auth::guard()`; ako padne → pošalje **401** i vrati `null`.
- `traziAdmina($res)` → prvo `traziKorisnika`, pa provjeri `jeAdmin`; ako nije admin → **403**.
- `jeAdmin($k)` → `strtoupper($k['uloga']) === 'ADMIN'` (usporedba neovisna o velikim slovima).
- `jsonTijelo($req,$res)` → provjeri `Content-Type: application/json` (inače **415**) i parsiraj
  JSON (inače **400**); vrati polje ili `null`.
- `pozitivanId($v,$res)` → provjeri da je `?id=` pozitivan cijeli broj (`ctype_digit`), inače **422**.

Obrazac u svakom kontroleru: `if ($this->traziAdmina($res) === null) return;` — ako provjera
ne prođe, helper je **već poslao** odgovor, pa metoda samo izađe. Tu se rađa razlika **401 vs 403**.

## `app/Controllers/HomeController.php`
**Što:** dijagnostika (nije dio domene).
**Kako:** `index()` vrati malo HTML-a (`$this->view`), `health()` vrati `{"status":"ok"}`,
`dbCheck()` pozove `Db::ping()` i javi radi li Oracle. Kratko i jednostavno.

## `app/Controllers/KorisniciController.php`  ⭐ (autentifikacija)
**Što:** registracija, prijava, odjava. (Ne nasljeđuje CRUD obrazac — poseban je.)
**Kako:**
- `register()` → provjeri JSON; validiraj `ime/email/lozinka` (**422**), lozinka ≥ 8 znakova;
  **`password_hash($lozinka, PASSWORD_BCRYPT)`** (bcrypt hash, nikad čisti tekst);
  `INSERT ... RETURNING korisnik_id INTO :novi_id`. Duplikat e-maila → Oracle `ORA-00001`
  (kršenje UNIQUE) → uhvati se i vrati **409**. Novi korisnik je uvijek `KUPAC` (default u bazi).
- `login()` → nađi po e-mailu; **`password_verify($lozinka, $hashIzBaze)`**. Krivo (ili nema
  korisnika) → **401** (ista poruka, da se ne otkriva postoji li e-mail). Dobro → `Jwt::encode`
  s `{id, ime, uloga, exp: time()+3600}` (token vrijedi 1 h) i vrati token.
- `logout()` → `Auth::guard()` (mora biti prijavljen), pa `INSERT` token u **`token_blacklist`**
  s `do_kad` = vrijeme isteka. Od tad `Auth::guard` odbija taj token.

## `app/Controllers/ProizvodiController.php`  ⭐⭐ (PUN primjer CRUD obrasca — pažljivo pročitaj)
**Što:** CRUD nad novčanicama. Ovo je "uzorak" po kojem rade i Kategorije.
**Kako, metoda po metoda:**

**`index()` — lista (javno), 4 koncepta odjednom:**
- **Paginacija:** `?stranica`, `?po_stranici` → izračuna `offset`; SQL završava
  `OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY`. Prvo se zasebnim `COUNT(*)` dohvati ukupan
  broj (za meta-podatke `stranica_od`).
- **Pretraga `?q=`:** `WHERE UPPER(p.naziv) LIKE UPPER(:q) OR UPPER(p.zemlja) LIKE UPPER(:q)`;
  `$qParam = '%'.$q.'%'` (LIKE s `%` = "sadrži"). Ako `q` prazan → `'%'` (sve).
- **Filteri:** `?kategorija_id`, `?godina`, `?ocuvanost` — uvjeti se **dodaju u polje** `$uvjeti`
  samo ako su poslani, pa se spoje (`implode(' AND ', ...)`). Bind se radi samo za poslane.
- **Whitelist sortiranje:** `?sort` se provjeri `in_array($sort, SORT_WHITELIST)` (popis
  `['id','naziv','cijena','godina','zaliha']`); ako nije u popisu → `id`. `?smjer` → samo
  `ASC`/`DESC`. **Zašto whitelist:** ime stupca se NE smije bindati (`oci_bind` radi samo za
  vrijednosti), pa bi lijepljenje korisnikovog teksta u SQL bio SQL injection — whitelist to
  sprječava jer su jedine moguće vrijednosti unaprijed dopuštene.
- Na kraju svaki redak ide kroz `mapiraj()` (Oracle redak → uredan JSON, s ugniježđenom
  `kategorija`). `LEFT JOIN kategorije` da proizvod bez kategorije i dalje izađe.

**`show()`** — jedan po `?id=` (`pozitivanId`), `LEFT JOIN` kategorija; nema reda → **404**.

**`store()` / `update()`** — samo admin (`traziAdmina` → 403) → `jsonTijelo` → `validiraj()`:
- `validiraj()` provjerava obavezna polja i pravila (naziv obavezan/≤200, `cijena` broj ≥ 0,
  `zaliha` cijeli ≥ 0, `godina` 1800–2100, `ocuvanost` iz whitelist, `kategorija_id` poz. broj);
  prazna polja postaju `null`. Greška → **422** s porukom; vrati `null` pa metoda izađe.
- SQL `INSERT ... RETURNING id` (store) ili `UPDATE ... WHERE id` (update), sve bind varijable.
  `update` gleda `oci_num_rows()==0` → **404** (nije našao taj id).
- Greške baze prevodi `mapirajGresku()`: `ORA-00001` (duplikat naziva) → **409**, `ORA-02291`
  (FK — kategorija ne postoji) → **409**, `ORA-02290` (CHECK prekršen) → **422**.

**`destroy()`** — admin; `DELETE WHERE id`; `oci_num_rows()==0` → **404**; `ORA-02292`
(proizvod je u nekoj narudžbi) → **409** (ne daj brisanje, čuva povijest).

> Sve operacije pisanja koriste `OCI_NO_AUTO_COMMIT` + `oci_commit` na kraju (ili `oci_rollback`
> u `catch`). Iako je ovdje jedan upit, obrazac je dosljedan kao i kod transakcija.

## `app/Controllers/KategorijeController.php` — samo RAZLIKE u odnosu na Proizvode
Isti CRUD obrazac (`index/show/store/update/destroy`, ista mehanika). Razlike:
- **Jednostavnija validacija** (inline, nema zasebne `validiraj`): samo `naziv` (obavezan, ≤100)
  i `opis` (≤500).
- **Sort whitelist** je manji: `['id','naziv']`.
- U `index`/`show` računa **`broj_proizvoda`** preko pod-upita
  `(SELECT COUNT(*) FROM proizvodi p WHERE p.kategorija_id = k.id)`.
- `destroy`: `ORA-02292` → **409** ("kategorija ima povezane proizvode") — ista logika kao kod
  proizvoda, druga poruka.

## `app/Controllers/KosaricaController.php` — samo ŠTO JE SPECIFIČNO
Sve rute traže prijavu (`traziKorisnika`), nema admina. Ključno: **vlasništvo** — svaki SQL ima
`WHERE korisnik_id = :kid`, pa korisnik vidi/mijenja isključivo svoju košaricu.
- `index()` — `JOIN proizvodi` da dobije naziv/cijenu/zalihu; usput zbroji `ukupno`.
- `store()` `{proizvod_id, kolicina}` — prvo provjeri da proizvod postoji (**404**) i da ima
  dovoljno zalihe (**409**); pa `INSERT`. Ako je par `(korisnik_id, proizvod_id)` već u
  košarici → UNIQUE prekršen → `ORA-00001` → **409** ("već u košarici, koristi PUT").
- `update()` `?proizvod_id=` `{kolicina}` — provjeri zalihu, `UPDATE ... WHERE korisnik+proizvod`;
  `oci_num_rows()==0` → **404** (te stavke nema u košarici).
- `destroy()` `?proizvod_id=` — `DELETE` te stavke.

## `app/Controllers/NarudzbeController.php`  ⭐⭐ (najvažnije — transakcije)
**Što:** checkout košarice u narudžbu, pregled i brisanje narudžbi.
**Kako:**

**`store()` — CHECKOUT (jedna transakcija, ne prima tijelo):**
1. `SELECT ... FROM stavke_kosarice JOIN proizvodi ... WHERE korisnik_id=:kid
   FOR UPDATE OF p.zaliha` izvršen s `OCI_NO_AUTO_COMMIT`. **`FOR UPDATE`** = zaključaj te
   retke proizvoda do kraja transakcije (druga istovremena kupnja mora čekati).
2. Prazna košarica → `oci_rollback` + **422**.
3. Petljom provjeri `kolicina > zaliha` → ako da, `rollback` + **409**; usput zbroji `ukupno`.
4. `INSERT INTO narudzbe ... RETURNING id INTO :nid` (zaglavlje).
5. Za svaku stavku: `INSERT INTO stavke_narudzbe` (**cijena se uzima iz baze**, `$s['CIJENA']`,
   ne od klijenta) + `UPDATE proizvodi SET zaliha = zaliha - :kol`.
6. `DELETE FROM stavke_kosarice WHERE korisnik_id=:kid` (isprazni košaricu).
7. `oci_commit` → **201**. Bilo koja greška u `try` → `catch` radi `oci_rollback` (sve se
   poništi — nema "pola narudžbe").

**Zašto sve ovo:** novčanice su unikati (`zaliha=1`). Bez transakcije + `FOR UPDATE` dvije
istovremene kupnje mogle bi obje skinuti zadnji primjerak. Ovako druga čeka, vidi `zaliha=0` i
dobije **409**.

**`destroy()` — brisanje narudžbe (vlasnik ili admin):** isto u transakciji, ali **obrnuto**:
`UPDATE proizvodi SET zaliha = zaliha + (...)` vrati zalihe → `DELETE stavke_narudzbe` →
`DELETE narudzbe`. Provjera vlasništva: ako nije admin i `korisnik_id` se ne poklapa → **403**.

**`index()` / `show()`:** kupac vidi **svoje** narudžbe (`WHERE n.korisnik_id=:kid`), admin
**sve** (`$filter` se uključi/isključi ovisno o `jeAdmin`). `index` je paginiran; `show` dohvati
zaglavlje + stavke (`JOIN proizvodi` za nazive). `TO_CHAR(n.datum, 'YYYY-MM-DD HH24:MI:SS')`
formatira Oracle datum u string.

---

# F. Baza podataka

## `database/schema.sql`
**Što:** kreira tablice (DDL). Skripta je **idempotentna** (smije se pokrenuti više puta).
**Kako:**
- Auth tablice (`korisnici`, `token_blacklist`) kreiraju se samo ako ne postoje: PL/SQL blok
  `BEGIN EXECUTE IMMEDIATE '...'; EXCEPTION WHEN OTHERS THEN IF SQLCODE != -955 THEN RAISE; END IF; END;`
  — `-955` je Oracle greška "ime već zauzeto", koju namjerno **progutaš** (sve ostale baci dalje).
  Slično `-1430` (stupac već postoji), `-942` (tablica ne postoji, kod DROP-a).
- 5 projektnih tablica se **drop-a pa kreira** (djeca prije roditelja). `GENERATED ALWAYS AS
  IDENTITY` = auto-ID (PK). Tu su svi **constrainti**: `UNIQUE` (email, naziv, parovi),
  `CHECK` (`cijena>=0`, `zaliha>=0`, `godina` 1800–2100, `ocuvanost`/`status`/`uloga` iz popisa),
  `FOREIGN KEY` (proizvodi→kategorije, košarica/narudžbe→korisnici/proizvodi).
- `q'[ ... ]'` je Oracleov način pisanja višeretčanog stringa (da apostrofi unutra ne smetaju).

## `database/seed.sql`
**Što:** ubaci demo podatke (pokrenuti NAKON sheme).
**Kako:**
- Demo korisnici preko **`MERGE`** (idempotentni "upsert"): ako e-mail postoji → `UPDATE`
  (postavi ulogu+lozinku), ako ne → `INSERT`. Lozinke su gotovi **bcrypt hashevi** za `tajna123`
  (`admin@banknote.hr`=ADMIN, `kupac@banknote.hr`=KUPAC). Zato ne moraš ručno registrirati.
- 6 kategorija + ~20 novčanica običnim `INSERT`-ima. `kategorija_id` se ne piše ručno nego
  dohvaća pod-upitom `(SELECT id FROM kategorije WHERE naziv = 'Hrvatska')` — radi neovisno o
  tome koji ID je auto-generiran. Neke imaju `zaliha=1` (unikati — za demo integriteta zalihe).
- `COMMIT;` na kraju potvrdi sve.

---

# G. Dijagnostika

## `public/health.php`
**Što:** samostalna HTML stranica koja provjeri okolinu (PHP verzija, OCI8, autoload, `.env`, DB).
**Kako:** NE ide kroz router (Apache je posluži direktno jer je postojeća datoteka). Ima isti
mini `.env` parser kao `Env.php`, složi polje `$checks`, pa `array_reduce($checks, fn($c,$i) =>
$c && $i['ok'], true)` izračuna je li **sve** OK. Donji dio je HTML s ubačenim PHP-om:
`<?= $allOk ? 'ok' : 'fail' ?>` je **kratki echo** (`<?=` = `echo`), a `<?php foreach (...) : ?>
... <?php endforeach; ?>` je petlja koja ispisuje redak po provjeru. `htmlspecialchars()` štiti
od ubacivanja HTML-a. Korisno kad nešto ne radi — otvoriš `/health.php` i odmah vidiš što fali.

---

## Redoslijed za obranu (kako pričati o kodu)

1. `public/index.php` → "svaki zahtjev ulazi ovdje" (front controller).
2. `Router` → kako se bira metoda kontrolera.
3. `BaseController` → kako se radi auth/validacija (401 vs 403).
4. `ProizvodiController` → kako izgleda jedan REST resurs (CRUD + paginacija + whitelist).
5. `NarudzbeController::store` → **transakcijski checkout** (glavni adut).
6. `schema.sql` → constrainti kao zadnja linija obrane.

> Za sve ostale kontrolere reci: "rade po istom obrascu kao Proizvodi, razlikuju se samo u
> resursu/validaciji" — to je upravo poanta zašto je kod ovako složen.
