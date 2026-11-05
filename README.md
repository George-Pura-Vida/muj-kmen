# Můj kmen – soukromá klientská aplikace

Produkční PHP aplikace pro jediného správce `jgjanousek@gmail.com`. Veřejná registrace, schvalování dalších účtů, reset hesla, ověřovací tokeny i změny účtu přes web jsou zakázané na serveru. Žádné heslo ani soukromé klientské údaje nepatří do tohoto veřejného repozitáře.

## Účet správce

Na hostingu spusťte v terminálu `php tools/admin-user.php`. Heslo program načítá pouze ze standardního vstupu; neposílejte je v příkazu, URL ani v Git konfiguraci. V terminálu lze skrýt vstup příkazem `stty -echo`, po zadání jej obnovit `stty echo`. Nástroj vytvoří nebo aktualizuje jediný povolený účet, uloží pouze hash, zneplatní staré relace a tokeny. Lze jej použít i pro administrátorskou změnu hesla. Přístup z webu je zakázán. Před prvním použitím nastavte účet tímto nástrojem nebo v privátní databázi `../muj-kmen-accounts/accounts.sqlite`. Existující účet není při nasazení přepsán.

## Import a export

V levém menu je import XLSX, XLS, CSV a JSON záloh (maximálně 12 MB, 10 000 klientů). Excel se nejprve čte lokálně v prohlížeči: vyberte list, řádek záhlaví a přiřazení sloupců, potom zkontrolujte náhled a potvrďte. Původní list `Matice klientů` má přednost; původní ikonové buňky a samostatný list `Evidence produktů` jsou podporované. Bez ID vzniká stabilní identifikátor podle jména; pro shodná jména přiřaďte skutečné ID. Neznámé produktové hodnoty se bezpečně označí k ověření. Obrázky, makra, šifrované soubory a libovolné dokumenty bez řádkové tabulky klientů se nepřevádějí automaticky. Data importu se po potvrzení validují na serveru a importují v transakci; existující ID se nepřepíší. Prioritní příznaky a přesnou zálohu zápisů zachovává JSON záloha; Excel slouží k importu klientské matice a produktové evidence.

Export Excel (.xlsx) a PDF na šířku zahrnuje aktuální filtrovaný výběr (všechny stránky), počty produktů a souhrn. Excel má zvláštní listy pro matici, evidenci a zápisy. PDF má českou diakritiku, barevné skupiny a produktové ikony. Soukromé soubory se nestahují přes cizí službu; knihovny jsou lokální.

## WebKitty

Repozitář: `https://github.com/George-Pura-Vida/muj-kmen.git`, **Deployment Branch: main** (větev master neexistuje). Dokumentový kořen musí směřovat do složky repozitáře. PHP 8.2+ se SQLite/PDO. Apache musí respektovat `.htaccess`. Databáze účtu i klientů jsou mimo veřejný kořen; ponechte jejich adresáře při dalším nasazení.

## Testy

`npm test` a `python3 tests/api_test.py` (vyžaduje PHP CLI, pdo_sqlite). Testy používají pouze fiktivní data a oddělené dočasné databáze. `PHP_BIN` a `PHP_EXTENSION_DIR` lze nastavit pro vlastní lokální runtime. Ukázka `/?demo=1` obsahuje pouze fiktivní klienty a nemůže ukládat ani importovat do serveru.
