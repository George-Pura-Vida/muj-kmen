# Můj kmen

Klientské centrum Jiřího Janouška pro PHP hosting WebKitty. Barevná matice A–E, šest produktů (zlato, stříbro, fondy, Bitcoin, švýcarské stříbro, pojištění), hledání bez diakritiky, filtry, grafy, obchodní a spolupracovní priority, zápisy a plánování kontaktů.

## WebKitty

- Doména: `https://muj-kmen.jirijanousek.cz/`
- Kořen webu: `/home/sites/1a/1/1f8017897e/muj-kmen/`
- Git: `https://github.com/George-Pura-Vida/muj-kmen.git`, větev `main`.
- PHP 8.1+ s PDO SQLite, sessions, ctype, JSON a funkčním odesíláním PHP `mail()`.
- Apache musí respektovat `.htaccess`. Žádný Node.js ani sestavování nejsou na produkci potřeba.

Naklonujte repozitář do uvedeného kořene webu přes Git Version Control. Nesměrujte doménu na celý domácí adresář hostingu.

Data se standardně ukládají **mimo kořen domény**: do sousedních adresářů `muj-kmen-data/` a `muj-kmen-accounts/`. PHP musí mít právo tyto adresáře vytvořit nebo do nich zapisovat. Umístění dat lze změnit pomocí `MK_DATA_DIR` a `MK_AUTH_DIR`; cestu klientské databáze lze též nastavit v lokálním `config.local.php` podle příkladu. Účty Moje renta a Můj kmen používají samostatné databáze, relace i obnovovací odkazy.

## Přihlášení a přístup

Přihlášení, registrace, ikona oka, potvrzení e-mailu, obnova hesla, profil a správa uživatelů vycházejí z modulu aplikace Moje renta. Přihlášení je e-mailem a heslem (minimálně 7 znaků), hesla se ukládají jako hash. Ověřovací odkaz má platnost 24 hodin, obnovovací 30 minut. Změna hesla ukončí původní relace.

Správcem je výhradně ověřený provozovatel uvedený v `AUTH_APPROVER_EMAIL`. Jednorázová aktivace dovoluje pouze tuto pevnou e-mailovou adresu. **Registrace i přihlášení jiných uživatelů jsou serverově zakázané**, a to i pro dříve uložené nebo ručně schválené jiné účty. Povolování dalších uživatelů je vypnuté. Profil správce obsahuje změnu hesla a obnovu přístupu.

Do tohoto veřejného repozitáře nepatří žádná klientská data, databáze, konfigurace ani hesla. Nejsou tu přednastavená produkční hesla. Připravený klientský import je samostatný soukromý JSON soubor. Import přidává nové profily a nepřepisuje existující úpravy; opakování importu nevytváří duplikáty. Záloha zahrnuje klienty i nové zápisy. CSV výběru obsahuje pouze zobrazené klienty a chrání tabulku před vzorci z textových polí.

## Význam ikon a statistik

Produktová ikona znamená záznam v podkladech; může vycházet i z pouhého štítku. Nepotvrzuje současnou držbu ani počet účtů. Zelený telefon znamená ověřit produkt nebo otevřené jednání, nikoli automaticky klientovi produkt nabídnout. Původní důkaz je v detailu klienta v záložce Podklady. Ruční potvrzení produktu se ukládá samostatně.

Hodnota v péči v přehledu zahrnuje A–E, nikoli „Mimo péči“. Graf hodnoty ukazuje i nezařazené zvlášť. Částky jsou hodnoty dle dodaného exportu; nejsou rozdělením mezi produkty ani úplným oceněním portfolia. Souhrny produktů zahrnují i pojištění. Grafy péče používají skutečně uložené zápisy, nikoli vymyšlenou historii. Priority jsou náměty k ověření, nikoli objednávky nebo prognóza provize.

## Testování

```sh
node --test tests/model.test.mjs
python3 tests/api_test.py
```

Druhý test potřebuje PHP CLI s PDO SQLite a ctype. Spouští lokální server s dočasnými databázemi a zachytává e-maily do lokální testovací schránky; nikomu je neodesílá. Ověřuje registraci, verifikaci, čekající přístup, schvalování, CSRF, import, duplicity, konkurenční úpravy, skutečné uložení zápisů, zálohu a zneplatnění relací po změně hesla. `MUJKMEN_TEST_MODE` funguje jen na PHP vývojovém serveru z localhostu; na produkčním PHP-FPM/Apache testovací režim neplatí.

Ukázka s výhradně fiktivními jmény je dostupná na `/?demo=1`; ukládání je v ní vypnuté. Přesné klientské údaje se nesmí vkládat do `assets/demo.js`.
