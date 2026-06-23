# Skripta za učenje — banknote.hr REST API
### Kolegij: Poslužiteljske Web Aplikacije · Cilj: ocjena 5 · Za potpune početnike

> **Kako koristiti ovu skriptu:** čitaj redom. Poglavlje 1 te uči taman toliko PHP-a da
> razumiješ kod. Poglavlja 2–10 su koncepti. Poglavlje 11 je demo, 12 su ispitna pitanja,
> 13 je "cheat sheet" za zadnjih 15 minuta prije obrane. Ako stigneš naučiti samo dvije
> stvari — nauči **JWT/uloge (pogl. 6)** i **transakcijski checkout (pogl. 9)**.

---

## 0. Projekt u jednoj rečenici (nauči napamet)

> "Ovo je **REST API backend** za web trgovinu kolekcionarskim novčanicama, napisan u
> **PHP-u** na vlastitom mini-MVC frameworku, s **Oracle** bazom, **JWT** prijavom i
> **bcrypt** lozinkama; glavna fora je **transakcijski checkout** koji sprječava da se isti
> unikat (zaliha=1) proda dvaput."

Ako na obrani kažeš samo tu rečenicu i razumiješ je — već si na dobrom putu.

---

## 1. PHP osnove (taman toliko da razumiješ kod)

Nikad nisi pisao PHP? Ne treba ti puno. Evo svega što se pojavljuje u projektu:

| Što vidiš u kodu | Što znači |
|---|---|
| `<?php` | početak PHP koda |
| `;` | kraj naredbe (obavezno) |
| `$ime` | varijabla (uvijek počinje s `$`) |
| `echo "tekst"` | ispiši nešto |
| `// ...` `/* ... */` | komentari |
| `function ime($a, $b) { ... }` | funkcija |
| `return $x;` | vrati vrijednost |
| `$polje = ['a', 'b']` | obično polje (lista) |
| `$mapa = ['kljuc' => 'vrijednost']` | **asocijativno polje** (kao mapa/dictionary) |
| `$mapa['kljuc']` | dohvat vrijednosti po ključu |
| `$x ?? 'default'` | "null coalescing": ako je `$x` null/ne postoji → koristi default |
| `$uvjet ? 'da' : 'ne'` | ternarni (kratki if/else) |
| `"Bok $ime"` | umetanje varijable u string (dvostruki navodnici) |
| `'tekst'` | običan string (bez umetanja) |
| `===` / `!==` | strogo jednako / nejednako (i tip i vrijednost) |
| `(int) $x`, `(float) $x`, `(string) $x` | pretvorba tipa (casting) |

**Objektno (klase) — projekt je sav u klasama:**

```php
namespace App\Core;          // "prezime" klase, da se ne sudaraju imena

final class Router {          // final = nitko ne smije nasljeđivati
  private array $routes = []; // svojstvo (varijabla u klasi)

  public function get($path, $handler) {   // metoda (funkcija u klasi)
    $this->routes['GET'][$path] = $handler; // $this = "ja, ovaj objekt"
  }
}
```

- `->`  pristup metodi/svojstvu objekta: `$router->get(...)`, `$this->routes`.
- `::`  pristup **statičkoj** (klasnoj) metodi, bez objekta: `Jwt::encode(...)`, `Db::connect()`.
- `public` (svatko), `private` (samo unutar klase), `protected` (klasa + naslijeđene).
- `abstract class BaseController` = "nedovršena" klasa od koje drugi nasljeđuju.
- `class X extends BaseController` = X nasljeđuje sve iz BaseControllera (`extends` = nasljeđuje).
- `use App\Core\Response;` = "uvezi" klasu iz drugog namespace-a da je možeš koristiti kraće.
- `declare(strict_types=1);` (na vrhu svake datoteke) = uključi stroge tipove (manje grešaka).

**To je dovoljno.** Sad možeš čitati svaku datoteku u projektu.

---

## 2. Što je projekt (domena)

REST API (poslužitelj) za trgovinu **kolekcionarskim novčanicama** (numizmatika). Nema
ekrana — vraća **JSON** podatke. Tri tipa korisnika:

- **Posjetitelj (javno):** pregledava katalog (kategorije, novčanice).
- **Kupac (prijavljen):** slaže **košaricu**, radi **narudžbu** (checkout).
- **Admin:** dodaje/mijenja/briše novčanice i kategorije, mijenja status narudžbi.

**Zašto novčanice?** Često su **unikati** (`zaliha = 1`). Zato je integritet zalihe pravi
problem: ako dvoje istovremeno kupuje zadnji primjerak, samo jedan smije proći. To rješava
**transakcija** (pogl. 9) — i to je glavni razlog zašto projekt zaslužuje 5.

---

## 3. Rječnik pojmova (objasni svaki u jednoj rečenici)

- **API** = sučelje preko kojeg programi razgovaraju (šalješ zahtjev, dobiješ odgovor).
- **REST** = stil API-ja gdje radiš s "resursima" (proizvodi, narudžbe) preko HTTP metoda.
- **HTTP metode (glagoli):** `GET` dohvati · `POST` stvori · `PUT` izmijeni · `DELETE` obriši.
- **Statusni kod** = broj u odgovoru koji kaže ishod (200 OK, 404 ne postoji, ...).
- **JSON** = tekstualni format za podatke: `{"naziv": "X", "cijena": 10}`.
- **MVC** = razdvajanje koda na slojeve (rutiranje / logika / podaci) radi preglednosti.
- **Front controller** = jedna ulazna datoteka (`public/index.php`) kroz koju ide **svaki** zahtjev.
- **Bind varijabla** = "rupa" u SQL-u (`:id`) koju baza sigurno popuni → brani od SQL injectiona.
- **SQL injection** = napad gdje napadač ubaci zlonamjeran SQL kroz unos; branimo se bind varijablama.
- **Whitelist** = popis dozvoljenih vrijednosti (npr. po čemu se smije sortirati).
- **JWT** = potpisani token koji dokazuje tko si, bez da server pamti sesiju.
- **bcrypt** = algoritam za sigurno (jednosmjerno) spremanje lozinki.
- **Transakcija** = grupa SQL naredbi koje uspiju **sve** ili **nijedna** (commit / rollback).
- **Constraint** = pravilo u bazi koje čuva ispravnost podataka (PK, FK, UNIQUE, CHECK).

---

## 4. Arhitektura — put jednog zahtjeva

Projekt je **slojevit**: svaka datoteka ima jednu odgovornost.

```
Klijent (Postman) ── HTTP zahtjev (npr. GET /api/proizvodi) ──►
  Apache + .htaccess  →  sve preusmjeri na  public/index.php   (FRONT CONTROLLER)
      → Env::load(.env)  (učita tajne)
      → Router  (učita rute iz routes/web.php + routes/api.php)
      → Router::dispatch()  → po (METODA + PUTANJA) nađe pravu funkciju (handler)
          → Controller metoda (npr. ProizvodiController::index)
              → provjera prijave/ovlasti (Auth::guard, BaseController helperi)
              → validacija ulaza
              → Db::connect() → SQL: oci_parse → oci_bind_by_name → oci_execute
          → Response::ok()/error()  → vrati JSON klijentu
```

**Slojevi i datoteke:**

| Sloj | Datoteke | Odgovornost |
|---|---|---|
| Ulaz | `public/index.php` | jedna ulazna točka |
| Core | `app/Core/{Router,Request,Response,Auth,Jwt,Env}.php` | HTTP, rutiranje, prijava |
| Controllers | `app/Controllers/*.php` | poslovna logika resursa |
| Services | `app/Services/Db.php` | spoj na Oracle (singleton) |
| Config | `config/*.php`, `.env` | lozinke baze, JWT tajna |

**Detalji koje je dobro spomenuti:**
- **Router** drži rute u polju `routes['GET']['/api/proizvodi'] = funkcija`. Traži **točan**
  tekst putanje (nema `/proizvodi/{id}`), zato ID ide kao **query parametar** `?id=N`.
  Ista `GET /api/proizvodi` zove `show()` ako ima `?id=`, inače `index()` (lista).
- **Response** — svaki odgovor ima isti oblik ("envelope"):
  `{"success": true/false, "data": ..., "message": "..."}`.
- **Db** koristi **singleton**: spoji se jednom, svi kasniji pozivi koriste istu vezu.

---

## 5. Baza podataka (ER model + ograničenja)

Oracle baza, 7 tablica: 2 za prijavu (`korisnici`, `token_blacklist`) + 5 projektnih.
Datoteka: `database/schema.sql`.

```
kategorije (šira država-grupa, npr. "Jugoslavija")
   │ 1..N
proizvodi (novčanice: cijena, zaliha, zemlja, godina, ocuvanost, kataloski_broj)
   │ 1..N                         │ 1..N
stavke_kosarice                stavke_narudzbe (kolicina + cijena-SNAPSHOT)
(košarica po korisniku)            │ N..1
   │ N..1                       narudzbe (zaglavlje: datum, ukupno, status)
korisnici ──1..N── narudzbe ───────┘
```

**Veze riječima:**
- `kategorije 1—N proizvodi` (kategorija = šira grupa "Jugoslavija"; `proizvodi.zemlja` =
  konkretni izdavatelj "SFRJ" — namjerno odvojeno, prati numizmatičku praksu).
- `korisnici 1—N stavke_kosarice N—1 proizvodi` (košarica po korisniku, bez zasebne tablice).
- `korisnici 1—N narudzbe 1—N stavke_narudzbe N—1 proizvodi` (to je **M:N** kupac↔novčanica
  ostvaren preko narudžbe).

**Constrainti (čuvaju integritet) — često pitanje:**
- **PK** (primarni ključ): jedinstveni `id`, auto-generiran (`GENERATED ALWAYS AS IDENTITY`).
- **FK** (strani ključ): veza na drugu tablicu (npr. `proizvodi.kategorija_id → kategorije.id`).
- **UNIQUE**: `email`, `naziv`, par `(korisnik_id, proizvod_id)` u košarici/stavci narudžbe.
- **CHECK**: `cijena>=0`, `zaliha>=0`, `godina` 1800–2100, `ocuvanost` iz popisa, `status` iz popisa.

**Zašto:** baza je **zadnja linija obrane** — i da kod pukne, baza neće dopustiti negativnu
zalihu, duplikat e-maila ni narudžbu za nepostojeći proizvod. Validacija je na **dvije
razine**: PHP (lijepe poruke) + baza (tvrdo jamstvo).

---

## 6. Autentifikacija i autorizacija  ⭐ (uči dobro)

- **Autentifikacija** = "tko si?" (prijava). **Autorizacija** = "smiješ li?" (ovlasti).

**Registracija** (`KorisniciController::register`): provjeri JSON → validiraj polja →
`password_hash($lozinka, PASSWORD_BCRYPT)` → `INSERT ... RETURNING id`. Novi korisnik je
**uvijek KUPAC** (nema samo-proglašavanja adminom). Duplikat e-maila → `ORA-00001` → **409**.

**Prijava** (`login`): nađi po e-mailu → `password_verify($lozinka, $hash)`. Krivo → **401**.
Dobro → izdaj **JWT** s `{id, ime, uloga, exp = sada+3600}` (vrijedi 1 h).

**JWT** = `header.payload.signature`:
- *payload* su podaci (id, uloga, exp) — **samo kodirani**, ne šifrirani (ne stavljaj tajne).
- *signature* = `HMAC-SHA256(header.payload, JWT_SECRET)` — potpis tajnim ključem koji zna
  samo server. Ako napadač promijeni `uloga` u `ADMIN`, potpis više ne valja.
- Provjera (`Jwt::decode`): server **ponovno izračuna** potpis i usporedi s `hash_equals`
  (otporno na timing napad), pa provjeri `exp`.

**Čuvar** (`Auth::guard`): pročita `Authorization: Bearer <token>` → `Jwt::decode` → provjeri
je li token na **`token_blacklist`** (odjavljen). Vrati podatke o korisniku.

**Uloge** (`BaseController`): `traziKorisnika()` → 401 ako nije prijavljen;
`traziAdmina()` → 403 ako je prijavljen ali nije admin.

**401 vs 403 (zlatno pitanje):**
- **401** = "ne znam tko si" (nema/loš token).
- **403** = "znam tko si, ali nemaš pravo" (kupac na admin ruti).

**Odjava** (`logout`): JWT je "bez stanja" i vrijedi do isteka; zato se pri odjavi token
ubaci na `token_blacklist` i od tada ga `guard()` odbija.

---

## 7. CRUD i REST (primjer: `ProizvodiController`)

**CRUD** = Create/Read/Update/Delete → `POST`/`GET`/`PUT`/`DELETE`.

| Radnja | HTTP | Ruta | Tko |
|---|---|---|---|
| Lista/pretraga | GET | `/api/proizvodi` | Javno |
| Jedan | GET | `/api/proizvodi?id=N` | Javno |
| Kreiraj | POST | `/api/proizvodi` | Admin |
| Izmijeni | PUT | `/api/proizvodi?id=N` | Admin |
| Obriši | DELETE | `/api/proizvodi?id=N` | Admin |

**`index()` pokriva 4 koncepta odjednom:**
- **Paginacija:** `?stranica=2&po_stranici=10` → `OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY`.
  Vraća i meta-podatke (ukupno, broj stranica). Zašto: ne šalješ tisuće redaka odjednom.
- **Pretraga:** `?q=` → `WHERE UPPER(naziv) LIKE UPPER(:q) OR UPPER(zemlja) LIKE ...`.
- **Filteri:** `?kategorija_id=`, `?godina=`, `?ocuvanost=` (dodaju se samo ako su poslani).
- **Whitelist sortiranje:** `?sort=cijena&smjer=desc`. **Ime stupca se NE smije bindati**,
  pa se provjerava je li u popisu `['id','naziv','cijena','godina','zaliha']`. Time se brani
  od SQL injectiona kroz ime stupca.

**`store()`/`update()`:** samo admin (`traziAdmina` → 403) → `validiraj()` (422 s porukom) →
SQL s bind varijablama. Greške baze se mapiraju na HTTP: `ORA-00001` (duplikat) → **409**,
`ORA-02291` (FK nema roditelja) → **409**, `ORA-02290` (CHECK) → **422**.

**`destroy()`:** admin; ako ništa nije obrisano (`oci_num_rows()==0`) → **404**; ako je
proizvod u narudžbi `ORA-02292` → **409** (čuva povijest narudžbi).

**Statusni kodovi (cijeli rječnik projekta):**
`200` OK · `201` Created (POST) · `400` loš JSON · `401` nije prijavljen · `403` nema ovlasti ·
`404` ne postoji · `409` konflikt (duplikat/FK/zaliha) · `415` krivi Content-Type ·
`422` validacija · `500` interna greška (logira se, klijent dobije generičku poruku).

---

## 8. Košarica (`KosaricaController`)

Sve rute traže prijavu; korisnik vidi/mijenja **samo svoju** košaricu (filter `korisnik_id`).
- `GET /api/kosarica` — stavke + izračunat `ukupno`.
- `POST /api/kosarica` `{proizvod_id, kolicina}` — prvo provjeri da proizvod postoji (404) i
  ima zalihe (409), pa `INSERT`. Ako je već u košarici (UNIQUE `(korisnik_id, proizvod_id)`)
  → `ORA-00001` → **409** (za promjenu količine koristi PUT).
- `PUT /api/kosarica?proizvod_id=N` `{kolicina}` — promijeni količinu (provjeri zalihu).
- `DELETE /api/kosarica?proizvod_id=N` — makni stavku.

---

## 9. GLAVNI ADUT — transakcijski checkout  ⭐⭐ (najvažnije za 5)

`POST /api/narudzbe` **ne prima tijelo** — uzme trenutnu košaricu korisnika i sve obavi u
**jednoj transakciji** (`OCI_NO_AUTO_COMMIT` + `oci_commit`/`oci_rollback`):

1. `SELECT ... FROM stavke_kosarice JOIN proizvodi ... WHERE korisnik_id=:kid
   FOR UPDATE OF p.zaliha` — dohvati **i zaključaj** retke proizvoda do kraja transakcije.
2. Prazna košarica → `rollback` + **422**.
3. Za svaku stavku provjeri zalihu; manjak → `rollback` + **409**. Usput zbroji `ukupno`.
4. `INSERT INTO narudzbe ... RETURNING id` (zaglavlje).
5. Za svaku stavku: `INSERT INTO stavke_narudzbe` (**cijena se uzima iz baze, ne od klijenta**)
   + `UPDATE proizvodi SET zaliha = zaliha - :kol`.
6. `DELETE FROM stavke_kosarice` (isprazni košaricu).
7. `oci_commit` → **201**. Bilo koja greška u 4–6 → `rollback` (narudžba se NE kreira napola).

**Što je `FOR UPDATE` (pesimistično zaključavanje):** zaključa odabrane retke pa ih druga
transakcija mora **čekati**. Tako dvije istovremene kupnje **ne mogu** obje skinuti zadnji
primjerak — druga čeka, vidi `zaliha=0` i dobije **409**. To je razlog *zašto* transakcije i
zaključavanje uopće postoje (numizmatika = unikati).

**Brisanje narudžbe** (`DELETE /api/narudzbe?id=N`) radi obrnuto, isto u transakciji:
vrati zalihe → obriši stavke → obriši zaglavlje. Vlasnik ili admin (inače 403).

**Zašto se cijena "zamrzava" u `stavke_narudzbe`:** ako kasnije promijeniš cijenu proizvoda,
povijesni iznos stare narudžbe ostaje točan (snapshot u trenutku kupnje).

---

## 10. Sigurnost (5 točaka — nabroji ih na obrani)

1. **SQL injection** → sve vrijednosti idu kroz `oci_bind_by_name`; imena stupaca (sort) →
   **whitelist** (nikad se ne bindaju).
2. **Lozinke** → `password_hash`/`password_verify` (bcrypt), nikad čisti tekst.
3. **JWT** → HS256, provjera potpisa (`hash_equals`), isteka (`exp`) i blackliste.
4. **Validacija ulaza** → Content-Type, JSON, obavezna polja, duljine, rasponi, whitelist.
5. **Odvajanje grešaka** → interna greška se logira (`error_log`), klijent dobije generičku
   poruku **500** bez detalja baze (da napadač ne dozna strukturu).

---

## 11. Demo scenarij (kako pokazati da radi — Postman)

Server pokreni: `php -S 127.0.0.1:8765 -t public public/index.php` (ili Apache).
Postman: Import kolekcije + environmenta iz `docs/postman/`. Demo admin je u seedu:
`admin@banknote.hr` / `tajna123`.

1. **Login admin** → token se sprema automatski.
2. Admin: `POST /api/kategorije`, `POST /api/proizvodi` → **201**.
3. **Register + login kupac** (drugi e-mail).
4. Kupac: `GET /api/proizvodi` (probaj `?q=`, `?godina=`, `?ocuvanost=`, `?sort=cijena&smjer=desc`).
5. Kupac: `POST /api/kosarica`, `GET /api/kosarica`.
6. Kupac: `POST /api/narudzbe` → **201**, zaliha umanjena, košarica prazna.
7. `GET /api/narudzbe?id=N` → narudžba sa stavkama.
8. Admin: `PUT /api/narudzbe?id=N` `{"status":"poslana"}`.

**Adut za demo (folder 5 "Integritet zalihe"):** pokušaj kupiti unikat (`zaliha=1`) dvaput →
drugi put **409**. To pokazuje transakciju i zaključavanje.

**Testovi grešaka (pokaži da svjesno vraćaš statuse):** ruta bez tokena → 401 · kupac na
admin ruti → 403 · nepostojeći id → 404 · duplikat → 409 · loš JSON → 400 · krivi
Content-Type → 415 · `ocuvanost='ZZ'` → 422.

---

## 12. Najvjerojatnija ispitna pitanja + kratki odgovori  ⭐ (zlato)

**Q: Što je REST API?**
A: Stil API-ja gdje se radi s resursima preko HTTP metoda (GET/POST/PUT/DELETE) i vraćaju se
standardni statusni kodovi; ovdje odgovori su JSON s `{success, data, message}`.

**Q: Što je front controller?**
A: Jedna ulazna datoteka (`public/index.php`) kroz koju prolazi svaki zahtjev; ona učita
okolinu, složi rute i pozove router.

**Q: Kako radi rutiranje?**
A: Router drži rute po metodi i putanji; `dispatch()` po (metoda + putanja) nađe funkciju.
Putanje su točne, pa ID ide kao `?id=N`.

**Q: Razlika 401 i 403?**
A: 401 = nisi prijavljen / loš token; 403 = prijavljen si ali nemaš ovlasti (npr. kupac na
admin ruti).

**Q: Što je JWT i zašto je siguran?**
A: Potpisani token (`header.payload.signature`). Potpis je HMAC-SHA256 s tajnim ključem; ako
netko promijeni payload, potpis ne valja. Server pamti samo tajnu, ne sesije.

**Q: Zašto bcrypt, a ne npr. MD5 ili čisti tekst?**
A: Lozinka se sprema jednosmjerno (hash) i sa "soli"; iz hasha se ne može vratiti lozinka.
MD5 je prebrz/slab; čisti tekst je katastrofa ako baza procuri.

**Q: Što je SQL injection i kako se braniš?**
A: Ubacivanje zlonamjernog SQL-a kroz unos. Branim se **bind varijablama** (`:id`) za sve
vrijednosti, a imena stupaca za sortiranje rješavam **whitelist-om**.

**Q: Što je transakcija i zašto ti treba u checkoutu?**
A: Grupa naredbi koje uspiju sve ili nijedna (commit/rollback). U checkoutu kreiram narudžbu,
prebacujem stavke, umanjujem zalihu i praznim košaricu — ako bilo što padne, rollback vrati
sve, da nema "pola narudžbe".

**Q: Što radi `FOR UPDATE`?**
A: Zaključa odabrane retke do kraja transakcije; druga istovremena kupnja mora čekati. Tako
se isti unikat (zaliha=1) ne može prodati dvaput.

**Q: Razlika POST i PUT?**
A: POST stvara novi resurs (vraća 201), PUT mijenja postojeći (vraća 200).

**Q: Čemu služe constrainti (PK/FK/UNIQUE/CHECK)?**
A: Čuvaju integritet podataka u bazi: jedinstveni identitet (PK), valjane veze (FK),
jedinstvenost (UNIQUE), dozvoljene vrijednosti (CHECK). Baza je zadnja linija obrane.

**Q: Zašto se cijena sprema u `stavke_narudzbe`?**
A: Da povijesni iznos narudžbe ostane točan i nakon što se promijeni cijena proizvoda (snapshot).

**Q: Razlika kategorija i polja `zemlja`?**
A: Kategorija je šira grupa ("Jugoslavija"); `zemlja` je konkretni izdavatelj ("SFRJ").

**Q: Kako radi odjava kad je JWT bez stanja?**
A: Token se doda na `token_blacklist`; `Auth::guard()` od tada odbija taj token iako mu je
potpis još valjan do isteka.

**Q: Što je singleton (kod `Db`)?**
A: Obrazac gdje postoji samo jedna instanca/veza; `Db::connect()` se spoji jednom i vraća
istu vezu svima.

**Q: Što vraćaš na neuspjeh i zašto generičku poruku?**
A: Internu grešku logiram (`error_log`) i klijentu vraćam 500 bez detalja baze, da napadač
ne dozna strukturu sustava.

---

## 13. Cheat sheet (zadnjih 15 minuta prije obrane)

```
PROJEKT:  REST API za trgovinu novčanicama · PHP + Oracle(OCI8) · JWT + bcrypt · mini-MVC
PUT ZAHTJEVA:  Apache→public/index.php→Router→Controller→Db(SQL)→Response(JSON)
SLOJEVI:  Core(Router/Request/Response/Auth/Jwt/Env) · Controllers · Services(Db) · config/.env
BAZA(7): korisnici, token_blacklist + kategorije→proizvodi, stavke_kosarice,
         narudzbe→stavke_narudzbe   | PK/FK/UNIQUE/CHECK
AUTH:  register→bcrypt(KUPAC) · login→JWT(id,uloga,exp1h) · guard→Bearer+potpis+blacklist
ULOGE: 401=nisi prijavljen · 403=nemaš ovlasti
CRUD:  GET/POST/PUT/DELETE · paginacija(OFFSET/FETCH) · whitelist sort · validacija(422)
STATUSI: 200 201 | 400 401 403 404 409 415 422 | 500
CHECKOUT(⭐): jedna transakcija · SELECT...FOR UPDATE(zaključa zalihu) · provjera→409 ·
         INSERT narudzbe+stavke(cijena iz baze) · UPDATE zaliha · DELETE košarica · commit/rollback
SIGURNOST: bind varijable · whitelist · bcrypt · JWT(hash_equals) · log+generic 500
```

> **Ako te pita "objasni svoj projekt":** kreni rečenicom iz pogl. 0, nacrtaj put zahtjeva
> (pogl. 4), spomeni bazu+constrainte (5), auth+uloge (6), i završi s transakcijskim
> checkoutom (9) kao glavnim adutom. To je peterica.
