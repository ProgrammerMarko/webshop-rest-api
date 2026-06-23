# Projektna dokumentacija - banknote.hr REST API

**Kolegij:** Poslužiteljske Web Aplikacije
**Tema:** Web trgovina kolekcionarskim novčanicama (numizmatika)
**Tehnologije:** PHP 8.1+ | Oracle (OCI8) | Apache/PHP-FPM | JWT | bcrypt

---

## 1. Uvod

Projekt je REST API backend za internet trgovinu kolekcionarskim novčanicama (po uzoru na
banknote.hr). Korisnici pregledavaju katalog novčanica razvrstanih po državama, prijavljeni
kupci slažu **košaricu** i kreiraju **narudžbu** (checkout), a administrator upravlja
katalogom i statusima narudžbi.

Projekt je izrađen na istom mini-MVC frameworku korištenom na laboratorijskim vježbama
(LV1-LV9) i demonstrira sve ondje obrađene koncepte na novoj, koherentnoj domeni.

### Zašto numizmatika dobro pristaje temi

Kolekcionarske novčanice su često **unikati** (`zaliha = 1`). Time je integritet zalihe
stvaran poslovni problem: ako dvoje istovremeno pokuša kupiti zadnji primjerak, samo jedan
smije uspjeti. Projekt to rješava **transakcijom uz zaključavanje retka** (`SELECT ... FOR
UPDATE`) pri checkoutu - konkretan, opipljiv razlog *zašto* transakcije i ograničenja baze
postoje.

---

## 2. Arhitektura

```
HTTP zahtjev
   |
   v
Apache + mod_rewrite  (.htaccess -> sve na public/index.php)
   |
   v
public/index.php (front controller)
   +-- Env::load(.env)
   +-- Router  <- routes/web.php + routes/api.php
   +-- Request / Response
   +-- Router::dispatch()
          |
          v
      Controller metoda
          +-- BaseController helperi: traziKorisnika / traziAdmina / jsonTijelo / pozitivanId
          +-- Auth::guard()  -> Jwt::decode + provjera token_blacklist
          +-- Db::connect()  -> OCI8 (oci_parse / oci_bind_by_name / oci_execute)
          +-- Response::ok() / Response::error()  -> standardni JSON
```

**Slojevi:**

| Sloj | Datoteke | Odgovornost |
|------|----------|-------------|
| Core | `app/Core/{Router,Request,Response,Auth,Jwt,Env}.php` | HTTP, rutiranje, autentifikacija |
| Services | `app/Services/Db.php` | OCI8 konekcija (singleton) |
| Controllers | `app/Controllers/*.php` | Poslovna logika resursa |
| Konfiguracija | `config/*.php`, `.env` | DB kredencijali, JWT tajna |

**Standardni format odgovora** (`Response::ok` / `Response::error`):

```json
{ "success": true,  "data": { ... }, "message": "OK" }
{ "success": false, "data": null,    "message": "Opis greške" }
```

---

## 3. Model baze (ER)

```
korisnici                       kategorije
---------                       ----------
korisnik_id (PK)                id (PK)
ime                             naziv (UQ)
prezime                         opis
email (UQ)                          | 1
lozinka (bcrypt)                    |
uloga (admin|kupac)                 | N
   | 1            1 |            proizvodi
   |               |            ---------
   | N           N |            id (PK)
stavke_kosarice ---+            naziv (UQ)
(korisnik_id, proizvod_id) UQ   opis, zemlja, godina,
kolicina                        ocuvanost, kataloski_broj
   |                            cijena (>=0), zaliha (>=0)
   |                            kategorija_id (FK->kategorije)
   | 1                                | 1
narudzbe                              |
--------                             N|
id (PK)                         stavke_narudzbe
korisnik_id (FK)                ---------------
datum, ukupno                   id (PK)
status (CHECK)  1 --------- N   narudzba_id (FK->narudzbe)
                                proizvod_id (FK->proizvodi)
                                kolicina, cijena (snapshot)
                                (narudzba_id, proizvod_id) UQ
```

**Relacije:**
- `kategorije 1-N proizvodi` - kategorija je **šira država-grupa** (npr. "Jugoslavija");
  polje `proizvodi.zemlja` je **konkretni izdavatelj** (npr. "SFRJ", "Kraljevina SHS").
- `korisnici 1-N stavke_kosarice N-1 proizvodi` - košarica po korisniku.
- `korisnici 1-N narudzbe 1-N stavke_narudzbe N-1 proizvodi` - **M:N** kupac<->novčanica
  ostvaren preko narudžbe.

**Ograničenja (integritet):** `UNIQUE` (naziv kategorije/proizvoda, par u košarici i
stavci narudžbe), `CHECK` (`cijena>=0`, `zaliha>=0`, `godina` 1800-2100, `ocuvanost`
whitelist, `status` whitelist, `uloga` whitelist), te `FOREIGN KEY` veze.
Detalji u [`../database/schema.sql`](../database/schema.sql).

---

## 4. Autentifikacija i autorizacija

- **Registracija** (`/api/auth/register`): lozinka se hashira s `password_hash(PASSWORD_BCRYPT)`;
  novi korisnik je uvijek `uloga = 'KUPAC'` (nema samododjele admin uloge).
- **Prijava** (`/api/auth/login`): `password_verify`, pa izdavanje **JWT** (HS256) s
  `id`, `ime`, **`uloga`** i `exp` (1 h).
- **Zaštita ruta**: `Auth::guard()` provjerava `Authorization: Bearer <token>`, potpis i
  istek tokena te **blacklistu** (odjava).
- **Uloge**:
  - **public** - pregled kataloga (GET kategorije/proizvodi)
  - **kupac** - košarica i vlastite narudžbe
  - **admin** - upravljanje katalogom i statusima narudžbi
- **Napomena:** tablice `korisnici` i `token_blacklist` dijele se s labosima; vrijednosti
  `uloga` zapisane su velikim slovima (`KUPAC`, `ADMIN`, `MODERATOR`), a usporedba uloge u
  kodu je neovisna o velikim/malim slovima.
- **Statusi**: `401` (nije prijavljen / loš token), **`403`** (prijavljen, ali nema ovlasti).

---

## 5. Popis endpointa

Legenda pristupa: **Javno** = bez tokena | **Kupac** = prijavljen korisnik | **Admin** = uloga admin

### Autentifikacija
| Metoda | Ruta | Pristup | Opis |
|--------|------|---------|------|
| POST | `/api/auth/register` | Javno | Registracija (uvijek `kupac`) |
| POST | `/api/auth/login` | Javno | Prijava -> JWT token |
| POST | `/api/auth/logout` | Kupac | Odjava (token na blacklistu) |

### Kategorije
| Metoda | Ruta | Pristup | Opis |
|--------|------|---------|------|
| GET | `/api/kategorije` | Javno | Paginirana lista (`q`, `sort`, `smjer`) + `broj_proizvoda` |
| GET | `/api/kategorije?id=N` | Javno | Jedna kategorija + `broj_proizvoda` |
| POST | `/api/kategorije` | Admin | Kreiranje |
| PUT | `/api/kategorije?id=N` | Admin | Ažuriranje |
| DELETE | `/api/kategorije?id=N` | Admin | Brisanje (409 ako ima proizvoda) |

### Proizvodi (novčanice)
| Metoda | Ruta | Pristup | Opis |
|--------|------|---------|------|
| GET | `/api/proizvodi` | Javno | Paginacija; `q` (naziv/zemlja); filteri `kategorija_id`, `godina`, `ocuvanost`; `sort` (`id,naziv,cijena,godina,zaliha`) |
| GET | `/api/proizvodi?id=N` | Javno | Jedna novčanica + kategorija |
| POST | `/api/proizvodi` | Admin | Kreiranje (validacija, 409 dup/FK) |
| PUT | `/api/proizvodi?id=N` | Admin | Ažuriranje |
| DELETE | `/api/proizvodi?id=N` | Admin | Brisanje (409 ako je u narudžbi) |

### Košarica
| Metoda | Ruta | Pristup | Opis |
|--------|------|---------|------|
| GET | `/api/kosarica` | Kupac | Stavke korisnika + `ukupno` |
| POST | `/api/kosarica` | Kupac | Dodaj `{proizvod_id, kolicina}` (409 ako već u košarici) |
| PUT | `/api/kosarica?proizvod_id=N` | Kupac | Promjena količine |
| DELETE | `/api/kosarica?proizvod_id=N` | Kupac | Ukloni stavku |

### Narudžbe
| Metoda | Ruta | Pristup | Opis |
|--------|------|---------|------|
| POST | `/api/narudzbe` | Kupac | **Checkout** košarice (transakcija) |
| GET | `/api/narudzbe` | Kupac/Admin | Kupac svoje, admin sve (paginirano) |
| GET | `/api/narudzbe?id=N` | Kupac/Admin | Narudžba + stavke (kupac samo svoju, inače 403) |
| PUT | `/api/narudzbe?id=N` | Admin | Promjena `status` |
| DELETE | `/api/narudzbe?id=N` | Kupac/Admin | Brisanje + povrat zalihe |

**Korišteni HTTP statusi:** `200`, `201`, `400` (loš JSON), `401`, `403`, `404`,
`409` (konflikt: duplikat / FK / nedovoljna zaliha), `415` (krivi Content-Type),
`422` (validacija), `500`.

---

## 6. Ključni koncept - transakcijski checkout

`POST /api/narudzbe` ne prima tijelo; uzima trenutnu košaricu korisnika i izvodi **jednu
transakciju** (`OCI_NO_AUTO_COMMIT` + `oci_commit`/`oci_rollback`):

1. `SELECT ... FROM stavke_kosarice JOIN proizvodi ... FOR UPDATE OF p.zaliha`
   -> dohvaća i **zaključava** retke proizvoda do kraja transakcije (sprječava da dvije
   istovremene kupnje rasprodaju isti zadnji primjerak).
2. Ako je košarica prazna -> `rollback` + `422`.
3. Provjera zalihe za svaku stavku; manjak -> `rollback` + `409`.
4. `INSERT INTO narudzbe ... RETURNING id` (zaglavlje).
5. Za svaku stavku: `INSERT INTO stavke_narudzbe` (cijena se uzima **iz baze**, ne od
   klijenta) + `UPDATE proizvodi SET zaliha = zaliha - :kol`.
6. `DELETE FROM stavke_kosarice` (pražnjenje košarice).
7. `oci_commit` -> `201`.

Bilo koja greška u koracima 4-6 izaziva `rollback` - narudžba se ne kreira djelomično.

> **Brisanje narudžbe** (`DELETE`) radi obrnuto u transakciji: vraća zalihe, briše stavke,
> pa zaglavlje.

---

## 7. Sigurnost

- **SQL injection**: sve vrijednosti idu kroz `oci_bind_by_name` (parametrizirano).
  Imena stupaca za sortiranje nikad se ne vežu - koristi se **whitelist** (`sort`, `smjer`).
- **Lozinke**: `password_hash`/`password_verify` (bcrypt), nikad u plain-textu.
- **JWT**: HS256, provjera potpisa (`hash_equals`), isteka i blackliste.
- **Validacija ulaza**: provjera `Content-Type`, JSON tijela, obaveznih polja, duljina,
  raspona (`godina`) i whitelisti (`ocuvanost`, `status`).
- **Odvajanje grešaka**: interne greške se logiraju (`error_log`), a klijent dobiva
  generičku poruku (`500`) bez detalja baze.

---

## 8. Mapiranje na ishode učenja (ocjena 5)

| Koncept (lab) | Gdje u projektu |
|---|---|
| DDL, constrainti (I1) | `schema.sql` - PK/FK/UNIQUE/CHECK |
| INSERT/SELECT/UPDATE/DELETE (I1) | svi kontroleri |
| Transakcije, ORA->HTTP (I1) | `NarudzbeController::store` (checkout), `destroy` (povrat zalihe) |
| REST, HTTP metode/statusi (I2) | svi endpointi, dosljedni status kodovi |
| Paginacija/filter/whitelist sort (I2) | `ProizvodiController::index`, `KategorijeController::index` |
| Autentifikacija bcrypt (I3) | `KorisniciController` |
| Autorizacija JWT + uloge (I3) | `Auth::guard`, `BaseController::traziAdmina` (403) |
| Validacija, bind varijable (I3) | `BaseController::jsonTijelo`, `ProizvodiController::validiraj` |

---

## 9. Pokretanje i testiranje

### Priprema
1. `composer install` (ili `composer dump-autoload`).
2. `cp .env.example .env`, popuni DB kredencijale i `JWT_SECRET`.
3. U Oracle alatu pokreni `database/schema.sql` pa `database/seed.sql`.
4. Posluži `public/` preko Apache + PHP-FPM (OCI8 omogućen). Uskladi `RewriteBase`
   (`public/.htaccess`) i `APP_URL` (`.env`).

### Demo scenarij (Postman kolekcija `docs/postman/`)
1. `register` (admin@...), pa u bazi `UPDATE korisnici SET uloga='ADMIN' WHERE email='admin@banknote.hr'; COMMIT;`
2. `login` kao admin -> token se automatski sprema u `{{token}}`.
3. Admin: `POST /api/kategorije`, `POST /api/proizvodi` -> `201`.
4. `register`/`login` kao kupac (drugi email).
5. Kupac: `GET /api/proizvodi` (isprobaj `?q=`, `?godina=`, `?ocuvanost=`, `?sort=cijena&smjer=desc`).
6. Kupac: `POST /api/kosarica`, `GET /api/kosarica`.
7. Kupac: `POST /api/narudzbe` -> `201`, zaliha umanjena, košarica prazna.
8. `GET /api/narudzbe?id=N` -> narudžba sa stavkama.
9. Admin: `PUT /api/narudzbe?id=N` `{"status":"poslana"}`.

### Testovi grešaka (očekivani statusi)
| Scenarij | Status |
|----------|--------|
| Zaštićena ruta bez tokena | 401 |
| Kupac na admin ruti (npr. `POST /api/proizvodi`) | 403 |
| Nepostojeći `id` | 404 |
| Duplikat naziva / brisanje referenciranog | 409 |
| Checkout s nedovoljnom zalihom | 409 (uz rollback) |
| Neispravan JSON | 400 |
| Krivi `Content-Type` | 415 |
| Promašena validacija (npr. `ocuvanost='ZZ'`) | 422 |

---

## 10. Dizajn odluke

- **Kategorija (država-grupa) vs `zemlja` (izdavatelj)** - namjerno odvojeno: kategorija
  grupira šire ("Jugoslavija"), a `zemlja` precizira izdavatelja ("SFRJ", "Kraljevina SHS").
  Nije redundantno i prati stvarnu numizmatičku praksu.
- **Košarica po korisniku bez zasebne tablice "košarica"** - `stavke_kosarice` ključana
  po `korisnik_id`; jednostavnije, a dovoljno (jedna aktivna košarica po korisniku).
- **Cijena se "zamrzava" u `stavke_narudzbe`** - povijesni iznos narudžbe ostaje točan i
  kad se kasnije promijeni cijena proizvoda.
- **`FOR UPDATE` pri checkoutu** - pesimistično zaključavanje sprječava prodaju istog
  unikata dvaput pri istovremenim zahtjevima.
- **Zajednički helperi u `BaseController`** (`traziKorisnika`, `traziAdmina`, `jsonTijelo`,
  `pozitivanId`) - uklanjaju ponavljanje i drže kontrolere čistima.
