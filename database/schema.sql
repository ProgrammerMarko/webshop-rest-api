-- ============================================================================
--  banknote.hr - shema baze (Oracle)
--  Pokrenuti kao skriptu (SQL*Plus / SQL Developer F5 / SQLcl).
--
--  Skripta je IDEMPOTENTNA i NEDESTRUKTIVNA prema auth tablicama:
--   - korisnici i token_blacklist se kreiraju samo ako ne postoje (čuva se postojeće)
--   - korisnici dobiva stupac 'uloga' (ako ga već nema)
--   - 5 projektnih tablica se uvijek iznova kreira (drop + create)
-- ============================================================================

-- ---- AUTH tablice: kreiraj samo ako ne postoje (ORA-00955 = ime već zauzeto) ----

BEGIN
  EXECUTE IMMEDIATE q'[
    CREATE TABLE korisnici (
      korisnik_id        NUMBER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
      ime                VARCHAR2(50)   NOT NULL,
      prezime            VARCHAR2(50)   DEFAULT '-' NOT NULL,
      email              VARCHAR2(100)  NOT NULL,
      lozinka            VARCHAR2(255)  NOT NULL,
      uloga              VARCHAR2(20)   DEFAULT 'KUPAC' NOT NULL,
      status             VARCHAR2(20)   DEFAULT 'NIJE LOGIRAN',
      datum_registracije TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT uq_korisnici_email   UNIQUE (email),
      CONSTRAINT chk_korisnici_uloga  CHECK (uloga IN ('KUPAC','ADMIN','MODERATOR')),
      CONSTRAINT chk_korisnici_status CHECK (status IN ('LOGIRAN','NIJE LOGIRAN'))
    )
  ]';
EXCEPTION WHEN OTHERS THEN IF SQLCODE != -955 THEN RAISE; END IF;
END;
/

BEGIN
  EXECUTE IMMEDIATE q'[
    CREATE TABLE token_blacklist (
      token  VARCHAR2(500) PRIMARY KEY,
      do_kad TIMESTAMP
    )
  ]';
EXCEPTION WHEN OTHERS THEN IF SQLCODE != -955 THEN RAISE; END IF;
END;
/

-- ---- Dodaj stupac 'uloga' na korisnici (ORA-01430 = stupac već postoji) ----

BEGIN
  EXECUTE IMMEDIATE q'[
    ALTER TABLE korisnici ADD (
      uloga VARCHAR2(20) DEFAULT 'KUPAC' NOT NULL
      CONSTRAINT chk_korisnici_uloga CHECK (uloga IN ('KUPAC','ADMIN','MODERATOR'))
    )
  ]';
EXCEPTION WHEN OTHERS THEN IF SQLCODE != -1430 THEN RAISE; END IF;
END;
/

-- ---- Projektne tablice: drop (ORA-00942 = ne postoji) pa create ----
--      Redoslijed brisanja: djeca prije roditelja.

BEGIN EXECUTE IMMEDIATE 'DROP TABLE stavke_narudzbe CASCADE CONSTRAINTS';
EXCEPTION WHEN OTHERS THEN IF SQLCODE != -942 THEN RAISE; END IF; END;
/
BEGIN EXECUTE IMMEDIATE 'DROP TABLE stavke_kosarice CASCADE CONSTRAINTS';
EXCEPTION WHEN OTHERS THEN IF SQLCODE != -942 THEN RAISE; END IF; END;
/
BEGIN EXECUTE IMMEDIATE 'DROP TABLE narudzbe CASCADE CONSTRAINTS';
EXCEPTION WHEN OTHERS THEN IF SQLCODE != -942 THEN RAISE; END IF; END;
/
BEGIN EXECUTE IMMEDIATE 'DROP TABLE proizvodi CASCADE CONSTRAINTS';
EXCEPTION WHEN OTHERS THEN IF SQLCODE != -942 THEN RAISE; END IF; END;
/
BEGIN EXECUTE IMMEDIATE 'DROP TABLE kategorije CASCADE CONSTRAINTS';
EXCEPTION WHEN OTHERS THEN IF SQLCODE != -942 THEN RAISE; END IF; END;
/

-- kategorije: šira država-grupa (npr. Hrvatska, Jugoslavija, Njemačka...)
CREATE TABLE kategorije (
  id    NUMBER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  naziv VARCHAR2(100) NOT NULL,
  opis  VARCHAR2(500),
  CONSTRAINT uq_kategorije_naziv UNIQUE (naziv)
);

-- proizvodi: kolekcionarske novčanice
CREATE TABLE proizvodi (
  id             NUMBER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  naziv          VARCHAR2(200)  NOT NULL,
  opis           VARCHAR2(1000),
  zemlja         VARCHAR2(80),                 -- konkretni izdavatelj, npr. 'SFRJ'
  godina         NUMBER(4),
  ocuvanost      VARCHAR2(5),                  -- numizmatička ocjena
  kataloski_broj VARCHAR2(50),
  cijena         NUMBER(10,2)   NOT NULL,
  zaliha         NUMBER         DEFAULT 0 NOT NULL,
  kategorija_id  NUMBER,
  kreiran_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_proizvodi_naziv      UNIQUE (naziv),
  CONSTRAINT chk_proizvodi_cijena    CHECK (cijena >= 0),
  CONSTRAINT chk_proizvodi_zaliha    CHECK (zaliha >= 0),
  CONSTRAINT chk_proizvodi_godina    CHECK (godina BETWEEN 1800 AND 2100),
  CONSTRAINT chk_proizvodi_ocuvanost CHECK (ocuvanost IN ('UNC','AU','XF','VF','F','VG','G')),
  CONSTRAINT fk_proizvodi_kategorija FOREIGN KEY (kategorija_id) REFERENCES kategorije(id)
);

-- stavke_kosarice: košarica po korisniku (jedna stavka po proizvodu)
CREATE TABLE stavke_kosarice (
  id          NUMBER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  korisnik_id NUMBER NOT NULL,
  proizvod_id NUMBER NOT NULL,
  kolicina    NUMBER NOT NULL,
  CONSTRAINT chk_kosarica_kolicina CHECK (kolicina > 0),
  CONSTRAINT uq_kosarica           UNIQUE (korisnik_id, proizvod_id),
  CONSTRAINT fk_kosarica_korisnik  FOREIGN KEY (korisnik_id) REFERENCES korisnici(korisnik_id),
  CONSTRAINT fk_kosarica_proizvod  FOREIGN KEY (proizvod_id) REFERENCES proizvodi(id)
);

-- narudzbe: zaglavlje narudžbe
CREATE TABLE narudzbe (
  id          NUMBER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  korisnik_id NUMBER NOT NULL,
  datum       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  ukupno      NUMBER(10,2) DEFAULT 0,
  status      VARCHAR2(20) DEFAULT 'nova' NOT NULL,
  CONSTRAINT chk_narudzbe_status   CHECK (status IN ('nova','placena','poslana','dostavljena','otkazana')),
  CONSTRAINT fk_narudzbe_korisnik  FOREIGN KEY (korisnik_id) REFERENCES korisnici(korisnik_id)
);

-- stavke_narudzbe: M:N veza narudžba <-> proizvod (cijena = snapshot u trenutku kupnje)
CREATE TABLE stavke_narudzbe (
  id          NUMBER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  narudzba_id NUMBER NOT NULL,
  proizvod_id NUMBER NOT NULL,
  kolicina    NUMBER NOT NULL,
  cijena      NUMBER(10,2) NOT NULL,
  CONSTRAINT chk_snar_kolicina CHECK (kolicina > 0),
  CONSTRAINT uq_snar           UNIQUE (narudzba_id, proizvod_id),
  CONSTRAINT fk_snar_narudzba  FOREIGN KEY (narudzba_id) REFERENCES narudzbe(id),
  CONSTRAINT fk_snar_proizvod  FOREIGN KEY (proizvod_id) REFERENCES proizvodi(id)
);

COMMIT;
