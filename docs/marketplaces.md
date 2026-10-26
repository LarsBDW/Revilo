# Revilo Marketplaces

Relay is geïntegreerd in de Laravel-admin op `/admin/marketplaces`. Het gebruikt de bestaande beheerlogin, database, publieke foto-opslag en Laravel-scheduler. Een aparte Node-server, aparte login of betaald koppelabonnement is niet nodig.

## Installatie na een Git-push

Voer in de bestaande Revilo-installatie de gebruikelijke deployment uit, inclusief:

```sh
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize:clear
```

De nieuwe migratie voegt uitsluitend vijf `marketplace_*`-tabellen toe. Bestaande klanten, orders, catalogus en voorraad worden niet opnieuw ingevoerd of gereset. Gebruik **geen** `migrate:fresh` en seed de productievoorraad niet opnieuw.

Bewaar de bestaande `APP_KEY`: OAuth-geheimen worden met Laravel `encrypted:array` versleuteld. Stel `APP_URL` in op het openbare HTTPS-adres. Foto's worden via willekeurige `/marketplace-photos/<token>`-URL's aangeboden, zodat marketplaces ze zonder adminlogin kunnen ophalen. Upload uitsluitend foto's die je openbaar wilt adverteren.

De bestaande scheduler (`php artisan schedule:run`, elke minuut) voert nu ook `marketplaces:process` uit. De normale webactie probeert na het HTTP-antwoord direct te verwerken; de scheduler vangt achtergebleven queued acties op. Er is geen extra betaalde queue-service nodig. Je kunt handmatig `php artisan marketplaces:process` uitvoeren. Een onderbroken running-actie wordt na tien minuten als onderbroken gemarkeerd en wordt niet blind opnieuw geplaatst.

## Een laptop toevoegen

1. Open **Marketplaces → Nieuw product**.
2. Vul merk, model, specs, conditieklasse, beschrijving, prijs en foto's in.
3. **Op Revilo publiceren na opslaan** staat standaard aan. Een gepubliceerde laptop moet een echte foto, positieve prijs en bevestigde staat hebben. Schakel dit uit om een ongepubliceerd concept te bewaren.
4. **Opslaan op Revilo** maakt een productpagina en één fysieke voorraadregel. Opnieuw opslaan wijzigt dezelfde laptop en maakt geen extra exemplaar.
5. Selecteer je ingestelde API-kanalen en gebruik **Opslaan & plaatsen**. De webshop is dan al opgeslagen; eventuele externe fouten worden afzonderlijk getoond en rollen de Revilo-catalogus niet terug.

**Bestaande voorraad** koppelt een bestaande laptop idempotent en voegt geen dubbele voorraad toe. Nieuw aangemaakte laptops krijgen voorlopig €0 inkoopkosten. Vul de echte inkoop-/refurbishmentkosten en eventueel serienummer aan bij **Laptopvoorraad** voordat je financiële marges beoordeelt.

De getoonde prijs is de verkoopprijs na een eventuele bestaande modelkorting. De basisprijs wordt bij opslaan zo aangepast dat de webshop en advertentie dezelfde getoonde prijs houden. Wanneer meerdere fysieke laptops één productmodel delen, krijgt de in Marketplaces bewerkte laptop een eigen productpagina. De andere laptops en hun oorspronkelijke productpagina blijven behouden.

Gereserveerde en verkochte voorraad kan niet via dit formulier terug beschikbaar worden gemaakt. Bestaande bestel-/retourregels blijven leidend. **Verkocht & beëindigen** werkt voor beschikbare of al verkochte laptops en vraagt verwijdering aan op gekoppelde API-kanalen. Externe verkopen worden niet automatisch uit de platformen opgehaald. Controleer na iedere verkoop alle kanalen; handmatige advertenties moeten handmatig worden verwijderd. Externe API-verzoeken zijn geen transactie met de webshop, dus een zeer korte race met een nieuwe webshopreservering blijft mogelijk.

## API-toegang

| Platform | Mogelijkheden | Voorwaarde |
| --- | --- | --- |
| eBay | Plaatsen, tekst/prijs/foto's aanpassen, status, beëindigen | Gratis Developers Program; eigen OAuth Client ID/Secret, gebruikers-refresh-token met `sell.inventory` en `sell.account`, inventory location en verzend-/betaal-/retourbeleid. Platformkosten kunnen gelden. |
| Marktplaats | Directe v2 API-connector | Vooraf goedgekeurde API-toegang en toegewezen OAuth-gegevens. Niet automatisch gratis. Geen GoedGeplaatst nodig. |
| 2dehands / 2ememain | Directe Belgische API met optionele Franse vertaling | Goedgekeurde toegang. Eén advertentie op het Belgische platform, geen dubbele plaatsing. |
| Facebook / Tweakers | Kopieerbare tekst en downloadbaar pakket | Geen automatische plaatsing of bewerking ingebouwd. |

Vul de gegevens zelf in bij **Koppelingen**; stuur accountwachtwoorden niet naar de app. De API-geheimen worden nooit teruggestuurd in de JSON-antwoorden. Een lege secret/token bij opnieuw opslaan behoudt het bestaande geheim. Wissel niet van account, land of sandbox nadat externe ID's zijn gekoppeld. Gebruik voor Sandbox een aparte testinstallatie met eigen database en keyset.

Gebruik **Zoekhulp → Account & beleid** om de toegang te testen en eBay-policy-ID's/locaties op te vragen. Gebruik de categoriezoekhulp voor categorieën en verplichte kenmerken. De producteditor heeft JSON-velden voor die kenmerken. Account-/categorieverplichtingen blijven gelden: een ingevulde sleutel is niet hetzelfde als goedgekeurde toegang.

Bij een timeout wordt een create niet blind herhaald. Controleer het externe platform en koppel onder **Herstel / bestaande advertentie** zo nodig het bestaande ID. Voor eBay is dit het **offer-ID**, niet het itemnummer. Het moet bij SKU `REVILO-UNIT-<voorraad-database-id>` horen. Gewone bestaande eBay-advertenties moeten eerst naar Inventory API zijn gemigreerd; deze integratie migreert ze niet.

## Verificatie

De tests gebruiken Laravel HTTP fakes en een geïsoleerde testdatabase. Ze verzenden geen echte advertenties. Live platformtoegang en productiehosting vereisen je eigen credentials en deployment. Voer de relevante tests uit met `php artisan test --filter=Marketplace` en bouw de assets met `npm run build`.

Bronnen: [eBay Developers](https://developer.ebay.com/), [eBay Inventory](https://developer.ebay.com/api-docs/sell/inventory/overview.html), [Marktplaats / 2dehands API](https://api.marktplaats.nl/docs/v2/).
