<?php
// Gedeelde standaardinhoud voor Committee Service Rollen, gebruikt door
// zowel committee_rollen.php (weergave) als api_commissie_rollen.php
// (voor "Reset naar standaardwaarden" en als basis bij het eerste keer
// bewerken van één rol). Wijzig deze inhoud alleen hier — nooit los in
// beide andere bestanden dupliceren.

function standaard_commissie_rollen() {
    return [
        [
            'naam' => 'PI Voorzitter',
            'sleutel' => 'voorzitter',
            'sobriety' => '2 jaar aaneengesloten (voorgesteld)',
            'ervaring' => '1 jaar committee-ervaring (voorgesteld)',
            'termijn' => '1 jaar (voorgesteld)',
            'taken' => [
                'Coördineert en stuurt alle commissie-activiteiten aan',
                'Stelt de agenda op en leidt de vergadering',
                'Maakt zich vertrouwd met de PI-richtlijnen en de 12 Tradities van C.A.',
                'Woont alle Area/District-vergaderingen bij, of wijst iemand van PI aan om dit te doen',
                'Woont Area/District PI-vergaderingen, online werkgroepen bij en neemt deel aan discussies en group conscience',
                'Neemt deel aan (en houdt toezicht op) het Area/District PI-communicatieplatform/forum/groep',
                'Onderhoudt regelmatig contact met alle Area/District PI-commissieleden',
                'Ondersteunt Area/District-groepen bij PI-gerelateerde vragen',
                'Zoekt actief naar mogelijkheden tot samenwerking met de professionele gemeenschap, andere organisaties en het algemeen publiek',
            ],
        ],
        [
            'naam' => 'PI Penningmeester',
            'sleutel' => 'penningmeester',
            'sobriety' => '1 jaar aaneengesloten (voorgesteld)',
            'ervaring' => '6 maanden committee-ervaring (voorgesteld)',
            'termijn' => '1 jaar (voorgesteld)',
            'taken' => [
                'Beheert de financiën van de commissie: inkomsten, uitgaven, en het saldo',
                'Brengt op elke vergadering verslag uit ("Treasurer\'s Report") van de actuele financiële stand',
                'Houdt transparante, controleerbare administratie bij van alle transacties',
                'Verifieert declaraties en doorverkopen die door leden zijn ingediend',
                'Zorgt dat de commissie zelfvoorzienend blijft (Traditie 7) — geen giften van buitenaf',
                'Maakt zich vertrouwd met de PI-richtlijnen en de 12 Tradities van C.A.',
            ],
        ],
        [
            'naam' => 'PI Secretaris',
            'sleutel' => 'secretaris',
            'sobriety' => '1 jaar aaneengesloten (voorgesteld)',
            'ervaring' => '6 maanden committee-ervaring (voorgesteld)',
            'termijn' => '1 jaar (voorgesteld)',
            'taken' => [
                'Maakt nauwkeurige notulen van elke commissievergadering',
                'Verstuurt de notulen tijdig per e-mail naar commissieleden en uploadt ze naar het gekozen platform van de commissie',
                'Houdt een overzicht bij van startdata en contactgegevens van PI-commissieleden',
                'Woont alle commissievergaderingen bij',
                'Maakt zich vertrouwd met de PI-richtlijnen en de 12 Tradities van C.A.',
                '(In dit portaal: beheert ook Notulen &amp; Agenda\'s in de Drive-database, en profielgegevens van leden)',
            ],
        ],
        [
            'naam' => 'PI Coördinator Literatuur',
            'sleutel' => 'coordinator_literatuur',
            'sobriety' => '1 jaar aaneengesloten (voorgesteld)',
            'ervaring' => '6 maanden committee-ervaring (voorgesteld)',
            'termijn' => '1 jaar (voorgesteld)',
            'taken' => [
                'Houdt voldoende literatuurvoorraad aan om aan de redelijke behoeften van de commissie te voldoen',
                'Verantwoordelijk voor het bestellen van literatuur bij de District Literatuur-secretaris',
                'Houdt uitgebreide en transparante administratie bij van geleverde en aangeschafte literatuur',
                'Maakt zich vertrouwd met de PI-richtlijnen en de 12 Tradities van C.A.',
                'Woont alle commissievergaderingen bij',
            ],
        ],
        [
            'naam' => 'PI Coördinator Drukwerk',
            'sleutel' => 'coordinator_drukwerk',
            'sobriety' => '1 jaar aaneengesloten (voorgesteld)',
            'ervaring' => '6 maanden committee-ervaring (voorgesteld)',
            'termijn' => '1 jaar (voorgesteld)',
            'taken' => [
                'Bepaalt waar en hoe posters/folders in de lokale gemeenschap verspreid moeten worden (huisartsen, apotheken, scholen, universiteiten, bibliotheken, gemeentegebouwen, etc.)',
                'Zorgt dat gedrukt materiaal verantwoord wordt verspreid en dat informatie actueel blijft',
                'Bereidt presentaties voor en geeft deze bij lokale diensten, onderwijsinstellingen en (niet-H&amp;I) organisaties in de gemeenschap',
                'Beheert de regionale voorraad, pakketten, leveranciers en prijzen (in dit portaal: de hele Drukwerk-module)',
                'Keurt bestellingen bij leveranciers goed of af',
                'Maakt zich vertrouwd met de PI-richtlijnen en de 12 Tradities van C.A.',
            ],
        ],
        [
            'naam' => 'PI Coördinator Voorlichting',
            'sleutel' => 'coordinator_voorlichting',
            'sobriety' => '1 jaar aaneengesloten (voorgesteld)',
            'ervaring' => '6 maanden committee-ervaring (voorgesteld)',
            'termijn' => '1 jaar (voorgesteld)',
            'taken' => [
                'Onderhoudt contact met de professionele gemeenschap: zorginstellingen, ziekenhuizen, klinieken, hulpverleningsorganisaties, justitie, scholen',
                'Behandelt en plant voorlichtingsaanvragen in (in dit portaal: de hele Voorlichting-module)',
                'Verzorgt of coördineert presentaties aan professionals ("Carrying the Message to the Professional Community")',
                'Bereidt eventueel de jaarlijkse "Professionals Week"-campagne voor (derde week van januari)',
                'Maakt zich vertrouwd met de PI-richtlijnen en de 12 Tradities van C.A.',
            ],
        ],
        [
            'naam' => 'PI Media Coördinator',
            'sleutel' => 'media_coordinator',
            'sobriety' => '1 jaar aaneengesloten (voorgesteld)',
            'ervaring' => '6 maanden committee-ervaring (voorgesteld)',
            'termijn' => '1 jaar (voorgesteld)',
            'taken' => [
                'Onderhoudt contact met lokale media (radio, pers, print, televisie)',
                'Verspreidt persberichten om positieve bekendheid over C.A. te geven',
                'Zet lokale advertenties of aankondigingen in om bekendheid te vergroten (gedrukte advertenties, online varianten, zoekmachine-advertenties)',
                'Reageert op vragen van lokale media over meetings en de fellowship',
                'Keurt fysieke campagnes en campagne-ideeën goed of af (drukwerk, pers, media) en is verantwoordelijk voor de uitvoering ervan (in dit portaal: bij PI Locaties → Campagnes/Campagne-ideeën, sectie "Fysiek")',
                'Maakt zich vertrouwd met de PI-richtlijnen en de 12 Tradities van C.A.',
            ],
        ],
        [
            'naam' => 'PI Coördinator Social Media',
            'sleutel' => 'social_media_coordinator',
            'sobriety' => '1 jaar aaneengesloten (voorgesteld)',
            'ervaring' => '6 maanden committee-ervaring (voorgesteld)',
            'termijn' => '1 jaar (voorgesteld)',
            'taken' => [
                'Beheert de social-mediakanalen van de commissie, met inachtneming van de Tradities en bescherming van anonimiteit',
                'Volgt de "Right Here, Right Now"-richtlijnen (digitale communicatiemiddelen voor PI-commissies)',
                'Keurt online/social-mediacampagnes en campagne-ideeën goed of af, en is verantwoordelijk voor de uitvoering ervan (in dit portaal: bij PI Locaties → Campagnes/Campagne-ideeën, sectie "Online")',
                'Maakt zich vertrouwd met de PI-richtlijnen en de 12 Tradities van C.A.',
            ],
        ],
        [
            'naam' => 'PI liaison',
            'sleutel' => 'lid',
            'sobriety' => '6 maanden (voorgesteld)',
            'ervaring' => 'Geen ervaring vereist',
            'termijn' => '1 jaar (voorgesteld)',
            'taken' => [
                'Woont de lokale PI-commissievergadering bij, haalt C.A.-literatuur/posters op en verspreidt deze lokaal',
                'Fungeert als schakel tussen de eigen thuisgroep en de lokale PI-commissie',
                'Ondersteunt de eigen groep actief door regelmatig literatuur/posters te plaatsen in de meetinglocatie/lokale omgeving',
                'Houdt groepsleden op de hoogte van aankomende PI-activiteiten en service-mogelijkheden',
                'Maakt zich vertrouwd met de PI-richtlijnen en de 12 Tradities van C.A.',
            ],
        ],
        [
            'naam' => 'District-IT (beheerder)',
            'sleutel' => 'admin',
            'sobriety' => '— (geen richtlijn in het handboek; dit is een technische/ondersteunende rol specifiek voor dit portaal)',
            'ervaring' => '—',
            'termijn' => '—',
            'taken' => [
                'Beheert dit PI-werkportaal: gebruikers, rollen, en toegangsrechten',
                'Heeft overal bekijk- én bewerkrechten, als vangnet voor alle andere rollen',
                'Beheert de Drive-database, technische instellingen en (waar van toepassing) integraties',
                'Ondersteunt alle andere commissieleden bij vragen over het portaal zelf',
            ],
        ],
    ];
}
