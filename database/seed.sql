-- ============================================================================
--  banknote.hr - testni podaci (seed)
--  Pokrenuti NAKON schema.sql.
--
--  Ukljucuje gotove DEMO KORISNIKE (nije potrebna rucna promocija/registracija):
--     admin@banknote.hr  / tajna123  / uloga ADMIN
--     kupac@banknote.hr  / tajna123  / uloga KUPAC
--  Oba MERGE-a su idempotentna (postave lozinku+ulogu i ako korisnik vec postoji).
-- ============================================================================

-- ---- Demo administrator (bcrypt hash lozinke 'tajna123') ----
MERGE INTO korisnici k
USING (SELECT 'admin@banknote.hr' AS email FROM dual) s
ON (k.email = s.email)
WHEN MATCHED THEN
  UPDATE SET k.uloga = 'ADMIN',
             k.lozinka = '$2y$12$5vqh1w3GLg5g0GOvT3frpeReHG4nTQejcT6o54zKyid6eUq1P/2wO'
WHEN NOT MATCHED THEN
  INSERT (ime, prezime, email, lozinka, uloga)
  VALUES ('Admin', 'Demo', 'admin@banknote.hr',
          '$2y$12$5vqh1w3GLg5g0GOvT3frpeReHG4nTQejcT6o54zKyid6eUq1P/2wO', 'ADMIN');

-- ---- Demo kupac (bcrypt hash lozinke 'tajna123') ----
MERGE INTO korisnici k
USING (SELECT 'kupac@banknote.hr' AS email FROM dual) s
ON (k.email = s.email)
WHEN MATCHED THEN
  UPDATE SET k.uloga = 'KUPAC',
             k.lozinka = '$2y$12$YQh63ncRAqUXb3.L0HwUcejEVzNRlBlgKvzwwNYdfewMlLx3mr.Qq'
WHEN NOT MATCHED THEN
  INSERT (ime, prezime, email, lozinka, uloga)
  VALUES ('Marko', 'Kupac', 'kupac@banknote.hr',
          '$2y$12$YQh63ncRAqUXb3.L0HwUcejEVzNRlBlgKvzwwNYdfewMlLx3mr.Qq', 'KUPAC');

-- ---- Kategorije (šira država-grupa) ----
INSERT INTO kategorije (naziv, opis) VALUES ('Hrvatska',    'Novčanice hrvatskih izdavatelja kroz povijest');
INSERT INTO kategorije (naziv, opis) VALUES ('Jugoslavija', 'Sve jugoslavenske države (Kraljevina SHS, DFJ, SFRJ, SRJ)');
INSERT INTO kategorije (naziv, opis) VALUES ('Njemačka',    'Njemački izdavatelji (Carstvo, Weimar, Reich)');
INSERT INTO kategorije (naziv, opis) VALUES ('Austrija',    'Austrija i Austro-Ugarska Monarhija');
INSERT INTO kategorije (naziv, opis) VALUES ('Italija',     'Talijanske lire i ostalo');
INSERT INTO kategorije (naziv, opis) VALUES ('Ostalo',      'Ostale svjetske novčanice');

-- ---- Proizvodi (novčanice). kategorija_id se dohvaća po nazivu kategorije. ----

-- Hrvatska
INSERT INTO proizvodi (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
VALUES ('1000 Kuna 1993', 'Prva serija hrvatske kune', 'Republika Hrvatska', 1993, 'UNC', 'P-29', 15.00, 5,
        (SELECT id FROM kategorije WHERE naziv = 'Hrvatska'));

INSERT INTO proizvodi (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
VALUES ('1000 Hrvatskih Dinara 1991', 'Prijelazna valuta 1991.-1994.', 'Republika Hrvatska', 1991, 'XF', 'P-22', 8.50, 3,
        (SELECT id FROM kategorije WHERE naziv = 'Hrvatska'));

INSERT INTO proizvodi (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
VALUES ('1000 Kuna 1941 NDH', 'Rijedak primjerak iz NDH razdoblja', 'NDH', 1941, 'VF', 'P-12', 40.00, 1,
        (SELECT id FROM kategorije WHERE naziv = 'Hrvatska'));

-- Jugoslavija
INSERT INTO proizvodi (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
VALUES ('100 Dinara 1965 SFRJ', NULL, 'SFRJ', 1965, 'UNC', 'P-80', 6.00, 10,
        (SELECT id FROM kategorije WHERE naziv = 'Jugoslavija'));

INSERT INTO proizvodi (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
VALUES ('1000 Dinara 1981 SFRJ', NULL, 'SFRJ', 1981, 'AU', 'P-92', 4.50, 8,
        (SELECT id FROM kategorije WHERE naziv = 'Jugoslavija'));

INSERT INTO proizvodi (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
VALUES ('500000 Dinara 1993 SRJ', 'Hiperinflacijska novčanica', 'SRJ', 1993, 'UNC', 'P-131', 3.00, 20,
        (SELECT id FROM kategorije WHERE naziv = 'Jugoslavija'));

INSERT INTO proizvodi (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
VALUES ('50 Dinara 1944 DFJ', 'Demokratska Federativna Jugoslavija', 'DFJ', 1944, 'F', 'P-52', 12.00, 2,
        (SELECT id FROM kategorije WHERE naziv = 'Jugoslavija'));

INSERT INTO proizvodi (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
VALUES ('1000 Dinara 1920 Kraljevina SHS', 'Vrlo rijetka, jedan primjerak', 'Kraljevina SHS', 1920, 'VG', 'P-23', 75.00, 1,
        (SELECT id FROM kategorije WHERE naziv = 'Jugoslavija'));

-- Njemačka
INSERT INTO proizvodi (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
VALUES ('1 Milijarda Maraka 1923', 'Notgeld iz doba hiperinflacije', 'Weimarska Republika', 1923, 'VF', 'P-114', 18.00, 4,
        (SELECT id FROM kategorije WHERE naziv = 'Njemačka'));

INSERT INTO proizvodi (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
VALUES ('20 Reichsmark 1939', NULL, 'Njemački Reich', 1939, 'XF', 'P-185', 22.00, 2,
        (SELECT id FROM kategorije WHERE naziv = 'Njemačka'));

INSERT INTO proizvodi (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
VALUES ('10 Mark 1906', 'Njemačko Carstvo', 'Njemačko Carstvo', 1906, 'F', 'P-9', 30.00, 1,
        (SELECT id FROM kategorije WHERE naziv = 'Njemačka'));

-- Austrija
INSERT INTO proizvodi (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
VALUES ('100 Schilling 1969', NULL, 'Austrija', 1969, 'AU', 'P-145', 14.00, 3,
        (SELECT id FROM kategorije WHERE naziv = 'Austrija'));

INSERT INTO proizvodi (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
VALUES ('20 Kronen 1913', 'Austro-Ugarska Monarhija', 'Austro-Ugarska', 1913, 'VF', 'P-13', 25.00, 2,
        (SELECT id FROM kategorije WHERE naziv = 'Austrija'));

-- Italija
INSERT INTO proizvodi (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
VALUES ('1000 Lira 1982', 'Marco Polo serija', 'Italija', 1982, 'UNC', 'P-109', 9.00, 6,
        (SELECT id FROM kategorije WHERE naziv = 'Italija'));

INSERT INTO proizvodi (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
VALUES ('500 Lira 1947', 'Rijedak poslijeratni primjerak', 'Italija', 1947, 'F', 'P-80', 35.00, 1,
        (SELECT id FROM kategorije WHERE naziv = 'Italija'));

-- Ostalo
INSERT INTO proizvodi (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
VALUES ('1 Dollar 1957 Silver Certificate', NULL, 'SAD', 1957, 'XF', 'P-419', 16.00, 4,
        (SELECT id FROM kategorije WHERE naziv = 'Ostalo'));

INSERT INTO proizvodi (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
VALUES ('5 Pounds 1971', 'Bank of England, Duke of Wellington', 'Velika Britanija', 1971, 'VF', 'P-378', 20.00, 3,
        (SELECT id FROM kategorije WHERE naziv = 'Ostalo'));

INSERT INTO proizvodi (naziv, opis, zemlja, godina, ocuvanost, kataloski_broj, cijena, zaliha, kategorija_id)
VALUES ('100 Francs 1978', NULL, 'Francuska', 1978, 'AU', 'P-154', 19.00, 2,
        (SELECT id FROM kategorije WHERE naziv = 'Ostalo'));

COMMIT;
