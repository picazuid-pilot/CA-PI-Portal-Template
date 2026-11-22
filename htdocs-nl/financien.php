<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$isFinancien = heeft_rol(['admin', 'penningmeester']);
$magVerifieren = heeft_rol(['admin', 'penningmeester']);
$magDerving = heeft_rol(['admin', 'penningmeester', 'secretaris', 'coordinator_drukwerk']);
$isVoorzitter = heeft_rol(['admin', 'voorzitter']);
$eigenUserId = $_SESSION['user_id'];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Financiën — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
<link rel="stylesheet" href="style.css.php" />
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://unpkg.com/papaparse@5.4.1/papaparse.min.js"></script>
<style>
  /* Datum- en bedragkolommen mogen niet afbreken naar een tweede regel — dat oogt onrustig en maakt de tabel onnodig hoog. */
  #transacties-body td:first-child, #transacties-body td:nth-child(5),
  #derving-body td:first-child, #derving-body td:nth-child(5),
  #aanvragen-body td:nth-child(1), #aanvragen-body td:nth-child(5),
  #posten-body td:nth-child(5), #posten-body td:nth-child(6) {
    white-space: nowrap;
  }
</style>
</head>
<body>
<?php include __DIR__ . '/partials/topbar.php'; ?>

<div class="container">

  <div class="toolbar">
    <div style="display:flex; gap:6px;">
      <button class="secondary" onclick="toonView('balans')">Balans</button>
      <button class="secondary" onclick="toonView('boekhouding')">Boekhouding</button>
      <button class="secondary" onclick="toonView('aanvragen')">Declaraties &amp; aanvragen</button>
      <button class="secondary" onclick="toonView('posten')">Doorverkopen</button>
      <button class="secondary" onclick="toonView('reroutes')">Gezamenlijke inkoop</button>
      <button class="secondary" onclick="toonView('bijlagen')">Bijlagen</button>
    </div>
    <div class="right">
      <button class="primary" onclick="openAanvraagForm('declaratie')">+ Declaratie indienen</button>
      <?php if ($isVoorzitter): ?><button class="primary" onclick="openAanvraagForm('bestelling_aanvraag')">+ Bestelling-aanvraag</button><?php endif; ?>
    </div>
  </div>

  <!-- ===== Balans ===== -->
  <div id="view-balans" class="card">
    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:20px;">
      <div class="card" style="text-align:center; position:relative;">
        <div style="color:var(--grey); font-size:12px;">Startsaldo</div>
        <div id="bal-startsaldo" style="font-size:22px; font-weight:700; color:var(--ink);">—</div>
        <?php if ($isFinancien): ?><button class="secondary" onclick="startsaldoBewerken()" style="position:absolute; top:6px; right:6px; padding:2px 8px; font-size:11px;">✎</button><?php endif; ?>
      </div>
      <div class="card" style="text-align:center;"><div style="color:var(--grey); font-size:12px;">Inkomsten</div><div id="bal-inkomsten" style="font-size:22px; font-weight:700; color:var(--status-groen);">—</div></div>
      <div class="card" style="text-align:center;"><div style="color:var(--grey); font-size:12px;">Uitgaven</div><div id="bal-uitgaven" style="font-size:22px; font-weight:700; color:var(--status-rood);">—</div></div>
      <div class="card" style="text-align:center;"><div style="color:var(--grey); font-size:12px;">Saldo</div><div id="bal-saldo" style="font-size:22px; font-weight:700; color:var(--primary);">—</div></div>
    </div>
    <strong>Uitgaven per categorie</strong>
    <div id="bal-categorieen" style="margin-top:8px;"></div>
  </div>

  <!-- ===== Boekhouding ===== -->
  <div id="view-boekhouding" class="card" style="display:none;">
    <div class="toolbar">
      <strong>Boekhouding — inkomsten &amp; uitgaven</strong>
      <div class="right">
        <a class="btn-primary" href="api_financien.php?action=export_transacties_csv">⬇ Exporteer CSV</a>
        <?php if ($isFinancien): ?>
        <a class="btn-primary" href="templates/financien_transacties_sjabloon.csv" download>⬇ CSV-sjabloon</a>
        <button class="secondary" onclick="document.getElementById('transactieCsvInput').click()">⬆ Bulk-import</button>
        <input type="file" id="transactieCsvInput" accept=".csv" style="display:none;" />
        <button class="primary" onclick="openTransactieForm()">+ Transactie toevoegen</button>
        <?php endif; ?>
      </div>
    </div>
    <table>
      <thead><tr><th>Datum</th><th>Type</th><th>Omschrijving</th><th>Categorie</th><th>Bedrag</th><th>Bijlage</th><th>Gecontroleerd</th><th></th></tr></thead>
      <tbody id="transacties-body"><tr><td colspan="7">Laden…</td></tr></tbody>
    </table>

    <div class="toolbar" style="margin-top:24px;">
      <strong>Derving — miskopen, verloren zendingen &amp; schade</strong>
      <div class="right">
        <a class="btn-primary" href="api_financien.php?action=export_derving_csv">⬇ Exporteer CSV</a>
        <?php if ($magDerving): ?><button class="primary" onclick="openDervingForm()">+ Derving registreren</button><?php endif; ?>
      </div>
    </div>
    <table>
      <thead><tr><th>Datum</th><th>Item</th><th>Aantal</th><th>Reden</th><th>Verlies</th><th>Toelichting</th><th></th></tr></thead>
      <tbody id="derving-body"><tr><td colspan="7">Laden…</td></tr></tbody>
    </table>

    <?php if ($isFinancien): ?>
    <div class="toolbar" style="margin-top:24px;">
      <strong>Opslagbeheer</strong>
    </div>
    <div id="opslag-status" style="font-size:13px; color:var(--grey); margin-bottom:8px;">Laden…</div>
    <div style="background:var(--border); border-radius:6px; height:8px; overflow:hidden; margin-bottom:12px;">
      <div id="opslag-balk" style="background:var(--primary); height:100%; width:0%;"></div>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <button class="secondary" onclick="opslagBackupDownloaden()">⬇ Download back-up van alle bijlagen</button>
      <button class="secondary" style="color:#c9432f; border-color:#c9432f;" onclick="opslagWissenNaBackup()">🗑 Wis lokale bijlagen (na back-up!)</button>
      <input type="file" id="opslag-herstel-input" accept=".zip" style="display:none;" onchange="opslagHerstellenVanuitBackup(this)" />
      <button class="secondary" onclick="document.getElementById('opslag-herstel-input').click()">♻️ Herstel vanuit back-up</button>
    </div>
    <div id="opslag-herstel-status" style="font-size:12.5px; color:var(--grey); margin-top:6px;"></div>
    <p style="font-size:11.5px; color:var(--grey); margin-top:6px;">Wissen verwijdert alleen de bestanden zelf van de server — de administratie (bedragen, omschrijvingen, bestandsnamen) blijft gewoon staan, met een label "alleen in back-up". Herstellen zet een eerder gedownloade back-up-zip weer terug, precies gekoppeld aan dezelfde transacties/declaraties als voorheen.</p>
    <?php endif; ?>
  </div>

  <!-- ===== Declaraties & aanvragen ===== -->
  <div id="view-aanvragen" class="card" style="display:none;">
    <div class="toolbar">
      <strong>Declaraties &amp; bestelling-aanvragen</strong>
      <div class="right">
        <a class="btn-primary" href="api_financien.php?action=export_aanvragen_csv">⬇ Exporteer CSV</a>
        <?php if ($isFinancien): ?>
        <a class="btn-primary" href="templates/financien_declaraties_sjabloon.csv" download>⬇ CSV-sjabloon (oude declaraties importeren)</a>
        <button class="secondary" onclick="document.getElementById('declaratieCsvInput').click()">⬆ Bulk-import</button>
        <input type="file" id="declaratieCsvInput" accept=".csv" style="display:none;" />
        <?php endif; ?>
      </div>
    </div>
    <table>
      <thead><tr><th>Nummer</th><th>Type</th><th>Aanvrager</th><th>Omschrijving</th><th>Bedrag</th><th>Status</th><th></th></tr></thead>
      <tbody id="aanvragen-body"><tr><td colspan="7">Laden…</td></tr></tbody>
    </table>
  </div>

  <!-- ===== Debiteuren & crediteuren ===== -->
  <div id="view-posten" class="card" style="display:none;">
    <div class="toolbar">
      <strong>Doorverkopen</strong>
      <div class="right">
        <a class="btn-primary" href="api_financien.php?action=export_posten_csv">⬇ Exporteer CSV</a>
        <?php if ($isFinancien): ?>
        <button class="secondary" onclick="openRelatieForm()">+ Relatie toevoegen</button>
        <?php endif; ?>
        <button class="primary" onclick="openPostForm()">+ Doorverkoop</button>
      </div>
    </div>

    <h3 style="color:var(--primary); margin:6px 0 8px;">Openstaande &amp; afgehandelde doorverkopen</h3>
    <table>
      <thead><tr><th>Nummer</th><th>Soort</th><th>Relatie</th><th>Regels</th><th>Totaal</th><th>Openstaand</th><th>Status</th><th></th></tr></thead>
      <tbody id="posten-body"><tr><td colspan="8">Laden…</td></tr></tbody>
    </table>

    <h3 style="color:var(--primary); margin:22px 0 8px;">Relaties</h3>
    <table>
      <thead><tr><th>Naam</th><th>Type</th><th>Contactpersoon</th><th>E-mail</th><th></th></tr></thead>
      <tbody id="relaties-body"><tr><td colspan="5">Laden…</td></tr></tbody>
    </table>
  </div>

  <!-- ===== Gezamenlijke inkoop ===== -->
  <div id="view-reroutes" class="card" style="display:none;">
    <div class="toolbar">
      <strong>Gezamenlijke inkoop — follow the money</strong>
      <?php if ($isFinancien): ?><button class="primary" onclick="openRerouteForm()">+ Nieuwe gezamenlijke inkoop</button><?php endif; ?>
    </div>
    <p style="color:var(--grey); font-size:12.5px;">Meerdere districten leggen samen in (bijdragen) voor een gezamenlijke bestelling bij één of meer leveranciers (aankopen). Elke regel wordt automatisch een eigen boeking in de hoofdboekhouding, allemaal gekoppeld via hetzelfde nummer.</p>
    <table>
      <thead><tr><th>Nummer</th><th>Titel</th><th>Bijdragen</th><th>Aankopen + kosten</th><th>Verschil</th><th></th></tr></thead>
      <tbody id="reroutes-body"><tr><td colspan="6">Laden…</td></tr></tbody>
    </table>
  </div>

  <!-- ===== Bijlagen-overzicht ===== -->
  <div id="view-bijlagen" class="card" style="display:none;">
    <div class="toolbar">
      <strong>Alle bijlagen</strong>
      <div class="right" style="display:flex; align-items:center; gap:10px;">
        <span id="bijlagen-aantal" style="color:var(--grey); font-size:12.5px;"></span>
        <input type="file" id="los-bijlage-input" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" onchange="losseBijlageUploaden(this)" />
        <button class="primary" onclick="document.getElementById('los-bijlage-input').click()">+ Bijlage uploaden</button>
      </div>
    </div>
    <p style="color:var(--grey); font-size:12.5px; margin:0 0 4px;">Verzameld uit Boekhouding, Declaraties &amp; aanvragen, Doorverkopen, Derving, en losse uploads. Klik op de naam voor een voorbeeld, dubbelklik om te hernoemen. 📎 = vervangen door nieuw bestand, 🔗 = samenvoegen met een andere bijlage (bijv. bij dubbel geüpload), ✕ = verwijderen. Een ×2/×3-badge betekent: aan meerdere plekken gekoppeld, maar 1 bestand.</p>
    <p style="color:var(--grey); font-size:12.5px; margin:0 0 12px;">Losse uploads kun je meteen koppelen aan een transactie/declaratie/doorverkoop/derving door daar bij bewerken op 🔗 te klikken en deze bijlage te kiezen.</p>
    <div id="los-bijlage-status" style="font-size:12.5px; color:var(--grey); margin-bottom:8px;"></div>
    <div style="display:flex; gap:8px; margin-bottom:10px;">
      <input id="bijlagen-zoek" placeholder="Zoeken op naam (bijv. Verzendkosten, MyParcel, Boete)…" oninput="renderBijlagenLijst()" style="flex:1; max-width:360px;" />
      <select id="bijlagen-sortering" onchange="renderBijlagenLijst()" style="width:auto;">
        <option value="naam-az">Naam A-Z</option>
        <option value="naam-za">Naam Z-A</option>
        <option value="datum-nieuw">Datum (nieuw → oud)</option>
        <option value="datum-oud">Datum (oud → nieuw)</option>
      </select>
    </div>
    <div style="display:flex; gap:18px; align-items:flex-start;">
      <div id="bijlagen-lijst" style="flex:0 0 360px; max-height:640px; overflow-y:auto; border-right:1px solid var(--border); padding-right:14px;"></div>
      <div id="bijlage-preview" style="flex:1; min-height:560px; display:flex; align-items:center; justify-content:center; background:var(--primary-light-95); border-radius:8px; overflow:hidden;">
        <p style="color:var(--grey); font-size:13px;">Selecteer een bijlage om 'm hier te bekijken.</p>
      </div>
    </div>
  </div>

</div>

<!-- ===== Modal: gezamenlijke inkoop aanmaken ===== -->
<div id="modal-reroute" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-reroute')">
  <div class="modal" style="max-width:680px;">
    <button class="modal-close" onclick="sluitModal('modal-reroute')">✕</button>
    <h2 id="ri-titel-kop">Nieuwe gezamenlijke inkoop</h2>
    <div class="form-grid">
      <div><label>Titel</label><input id="ri-titel" placeholder="bijv. Gezamenlijke drukwerkbestelling maart 2026" /></div>
      <div><label>Datum (dag-maand-jaar)</label><input id="ri-datum" type="text" placeholder="dd-mm-jjjj" inputmode="numeric" /></div>
    </div>
    <p style="font-size:11.5px; color:var(--grey); margin-top:-8px;">Deze datum geldt als standaardwaarde voor nieuwe boekingen. Bij bewerken blijft een datum die je al los in de Boekhouding hebt aangepast automatisch behouden — deze schermdatum overschrijft die niet.</p>

    <h3 style="color:var(--primary); margin:16px 0 6px;">Bijdragen (wie legt wat in)</h3>
    <div id="ri-bijdragen-lijst"></div>
    <button type="button" class="secondary" onclick="voegRerouteRegelToe('bijdragen')">+ Bijdrage toevoegen</button>
    <p style="margin:8px 0; font-size:13px;">Totaal bijdragen: <strong id="ri-totaal-bijdragen">€ 0,00</strong></p>

    <h3 style="color:var(--primary); margin:16px 0 6px;">Aankopen (waar is het geld naartoe)</h3>
    <div id="ri-aankopen-lijst"></div>
    <button type="button" class="secondary" onclick="voegRerouteRegelToe('aankopen')">+ Aankoop toevoegen</button>
    <p style="margin:8px 0; font-size:13px;">Totaal aankopen: <strong id="ri-totaal-aankopen">€ 0,00</strong></p>

    <h3 style="color:var(--primary); margin:16px 0 6px;">Bijkomende kosten (bijv. verzendkosten om spullen bij de juiste ontvanger te krijgen)</h3>
    <div id="ri-bijkomendeKosten-lijst"></div>
    <button type="button" class="secondary" onclick="voegRerouteRegelToe('bijkomendeKosten')">+ Kosten toevoegen</button>
    <p style="margin:8px 0; font-size:13px;">Totaal bijkomende kosten: <strong id="ri-totaal-bijkomendeKosten">€ 0,00</strong></p>

    <p id="ri-verschil" style="font-size:13px; font-weight:600;"></p>

    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="rerouteOpslaan()">Opslaan</button>
    </div>
  </div>
</div>

<!-- ===== Modal: gezamenlijke inkoop grafiek ===== -->
<div id="modal-flow-graph" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-flow-graph')">
  <div class="modal" style="max-width:900px;">
    <button class="modal-close" onclick="sluitModal('modal-flow-graph')">✕</button>
    <h2 id="rg-titel">Gezamenlijke inkoop</h2>
    <div id="rg-svg-container" style="overflow-x:auto;"></div>
    <div id="rg-details" style="margin-top:16px;"></div>
  </div>
</div>

<!-- ===== Modal: transactie toevoegen/bewerken ===== -->
<div id="modal-transactie" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-transactie')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-transactie')">✕</button>
    <h2 id="transactie-titel">Transactie toevoegen</h2>
    <div class="form-grid">
      <div><label>Type</label>
        <select id="t-type"><option value="uitgave">Uitgave</option><option value="inkomst">Inkomst</option></select>
      </div>
      <div><label>Bedrag (€)</label><input id="t-bedrag" type="number" step="0.01" min="0" /></div>
      <div><label>Datum (dag-maand-jaar)</label><input id="t-datum" type="text" placeholder="dd-mm-jjjj" inputmode="numeric" /></div>
      <div><label>Categorie</label>
        <select id="t-categorie-select" onchange="categorieDropdownGewijzigd()"></select>
        <input id="t-categorie-overig" type="text" placeholder="Eigen categorie" style="display:none; margin-top:6px;" />
      </div>
    </div>
    <label>Omschrijving</label><textarea id="t-omschrijving" rows="2"></textarea>
    <label>Bijlagen (factuur/bonnetje — PDF, JPG of PNG)</label>
    <input type="file" id="t-bijlage" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" />
    <button type="button" class="secondary" onclick="document.getElementById('t-bijlage').click()">+ Bijlage toevoegen</button>
    <button type="button" class="secondary" onclick="openBijlageKiezerVoorArray('T')">🔗 Bestaande bijlage selecteren</button>
    <div id="t-bijlage-status" style="font-size:12px; color:var(--grey); margin-top:4px;"></div>
    <div id="t-bijlagen-lijst" style="margin-top:6px;"></div>
    <label>Referentie (optioneel — koppel aan gezamenlijke inkoop, doorverkoop of declaratie/aanvraag)</label>
    <div class="form-grid">
      <div>
        <select id="t-referentie-type" onchange="vulReferentieOpties()">
          <option value="">— geen referentie —</option>
          <option value="reroute">Gezamenlijke inkoop (GI-...)</option>
          <option value="post">Doorverkoop (DV-...)</option>
          <option value="aanvraag">Declaratie/aanvraag (DC-.../BA-...)</option>
        </select>
      </div>
      <div><select id="t-referentie-id"><option value="">— kies eerst een type —</option></select></div>
    </div>
    <div style="margin-top:14px; display:flex; justify-content:flex-end; gap:8px;">
      <button class="secondary" onclick="sluitModal('modal-transactie')">Annuleren</button>
      <button class="primary" onclick="opslaanTransactie()">Opslaan</button>
    </div>
  </div>
</div>

<!-- ===== Modal: derving registreren ===== -->
<div id="modal-derving" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-derving')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-derving')">✕</button>
    <h2>Derving registreren</h2>
    <label>Item</label>
    <select id="d-item"></select>
    <div class="form-grid">
      <div><label>Aantal</label><input id="d-aantal" type="number" min="1" value="1" /></div>
      <div><label>Reden</label>
        <select id="d-reden">
          <option value="miskoop">Miskoop</option>
          <option value="verloren_zending">Verloren zending</option>
          <option value="schade">Schade</option>
          <option value="overig">Overig</option>
        </select>
      </div>
    </div>
    <p id="d-verlies-preview" style="font-size:13px; color:var(--grey);"></p>
    <label>Toelichting (optioneel)</label><textarea id="d-toelichting" rows="2"></textarea>
    <label>Foto's/bewijs (optioneel — PDF, JPG of PNG, meerdere mogelijk)</label>
    <input type="file" id="d-bijlage" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" />
    <button type="button" class="secondary" onclick="document.getElementById('d-bijlage').click()">+ Bijlage toevoegen</button>
    <button type="button" class="secondary" onclick="openBijlageKiezerVoorArray('D')">🔗 Bestaande bijlage selecteren</button>
    <div id="d-bijlagen-lijst" style="margin-top:6px;"></div>
    <div id="d-bijlage-status" style="font-size:12px; color:var(--grey); margin-top:4px;"></div>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="dervingOpslaan()">Registreren</button>
    </div>
  </div>
</div>

<!-- ===== Modal: declaratie / bestelling-aanvraag indienen ===== -->
<div id="modal-aanvraag" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-aanvraag')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-aanvraag')">✕</button>

    <div id="aanvraag-formulier-velden">
      <h2 id="aanvraag-titel">Declaratie indienen</h2>
      <div class="form-grid">
        <div><label>Bedrag (€)</label><input id="a-bedrag" type="number" step="0.01" min="0" /></div>
        <div><label>Datum (dag-maand-jaar)</label><input id="a-datum" type="text" placeholder="dd-mm-jjjj" inputmode="numeric" /></div>
      </div>
      <label id="a-leverancier-label" style="display:none;">Leverancier</label>
      <input id="a-leverancier" style="display:none;" />
      <label>Omschrijving</label><textarea id="a-omschrijving" rows="2" placeholder="Waar was dit voor?"></textarea>
      <label>Bijlagen (betaalbewijs/factuur — PDF, JPG of PNG, meerdere mogelijk)</label>
      <input type="file" id="a-bijlage" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" />
      <button type="button" class="secondary" onclick="document.getElementById('a-bijlage').click()">+ Bijlage toevoegen</button>
      <button type="button" class="secondary" onclick="openBijlageKiezerVoorArray('A')">🔗 Bestaande bijlage selecteren</button>
      <div id="a-bijlagen-lijst" style="margin-top:6px;"></div>
      <div id="a-bijlage-status" style="font-size:12px; color:var(--grey); margin-top:4px;"></div>
      <div style="margin-top:14px; display:flex; justify-content:flex-end; gap:8px;">
        <button class="secondary" onclick="sluitModal('modal-aanvraag')">Annuleren</button>
        <button class="primary" onclick="opslaanAanvraag()">Indienen</button>
      </div>
    </div>

    <div id="aanvraag-succes" style="display:none; text-align:center; padding:10px 0;">
      <p style="font-size:32px; margin:0;">✓</p>
      <p style="color:var(--primary); font-weight:600;">Ingediend! De penningmeester heeft een melding gekregen (portaal + e-mail).</p>
      <a id="aanvraag-whatsapp-link" href="#" target="_blank" rel="noopener" class="btn-primary" style="margin-top:6px;">📱 Stuur ook even via WhatsApp</a>
      <br><button class="secondary" style="margin-top:14px;" onclick="sluitAanvraagModalNaSucces()">Sluiten</button>
    </div>
  </div>
</div>

<!-- ===== Modal: aanvraag beoordelen (financiën-rol) ===== -->
<div id="modal-besluit" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-besluit')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-besluit')">✕</button>
    <h2>Aanvraag beoordelen</h2>
    <p id="besluit-info" style="color:var(--grey); font-size:13.5px;"></p>
    <label>Opmerking (optioneel)</label><textarea id="besluit-opmerking" rows="2"></textarea>
    <div style="margin-top:14px; display:flex; justify-content:flex-end; gap:8px;">
      <button class="secondary" onclick="besluitNemen('afgewezen')" style="color:#c9432f; border-color:#c9432f;">Afwijzen</button>
      <button class="primary" onclick="besluitNemen('goedgekeurd')">Goedkeuren</button>
    </div>
  </div>
</div>

<!-- ===== Modal: betaalgegevens doorgeven (aanvrager) ===== -->
<div id="modal-betaalgegevens" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-betaalgegevens')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-betaalgegevens')">✕</button>
    <h2>Betaalgegevens doorgeven</h2>
    <p style="color:var(--grey); font-size:13px;">Je aanvraag is goedgekeurd — geef door waar het bedrag naartoe overgemaakt moet worden.</p>
    <label>IBAN</label><input id="bg-iban" placeholder="NL00 BANK 0000 0000 00" />
    <label>Ten name van</label><input id="bg-tnv" />
    <label>Opmerking (optioneel)</label><textarea id="bg-opmerking" rows="2"></textarea>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="betaalgegevensOpslaan()">Versturen</button>
    </div>
  </div>
</div>

<!-- ===== Modal: relatie toevoegen/bewerken ===== -->
<div id="modal-relatie" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-relatie')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-relatie')">✕</button>
    <h2 id="relatie-titel">Relatie toevoegen</h2>
    <label>Naam</label><input id="r-naam" placeholder="bijv. Regio Noord, of naam leverancier" />
    <label>Type</label>
    <select id="r-type"><option value="beide">Debiteur & crediteur</option><option value="debiteur">Debiteur</option><option value="crediteur">Crediteur</option></select>
    <div class="form-grid">
      <div><label>Contactpersoon</label><input id="r-contactpersoon" /></div>
      <div><label>E-mail</label><input id="r-email" type="email" /></div>
    </div>
    <label>Telefoon</label><input id="r-telefoon" />
    <label>Notitie</label><textarea id="r-notitie" rows="2"></textarea>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="relatieOpslaan()">Opslaan</button>
    </div>
  </div>
</div>

<!-- ===== Modal: doorverkoop / inkoop op rekening aanmaken ===== -->
<div id="modal-post" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-post')">
  <div class="modal" style="max-width:640px;">
    <button class="modal-close" onclick="sluitModal('modal-post')">✕</button>
    <h2 id="post-titel">Doorverkoop aanmaken</h2>
    <div class="form-grid">
      <div><label>Relatie</label><select id="p-relatie"></select></div>
      <div><label>Datum (dag-maand-jaar)</label><input id="p-datum" type="text" placeholder="dd-mm-jjjj" inputmode="numeric" /></div>
    </div>
    <div id="p-factuur-velden" style="display:none;">
      <label>Factuurnummer (van de leverancier)</label><input id="p-factuurnummer" />
      <label>Factuur/facturen (PDF/foto, meerdere mogelijk)</label>
      <input type="file" id="p-bijlage" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" />
      <button type="button" class="secondary" onclick="document.getElementById('p-bijlage').click()">+ Bijlage toevoegen</button>
      <button type="button" class="secondary" onclick="openBijlageKiezerVoorArray('P')">🔗 Bestaande bijlage selecteren</button>
      <div id="p-bijlagen-lijst" style="margin-top:6px;"></div>
      <div id="p-bijlage-status" style="font-size:12px; color:var(--grey); margin-top:4px;"></div>
    </div>

    <h3 style="color:var(--primary); margin:16px 0 6px;">Regels</h3>
    <div id="p-regels-lijst"></div>

    <div style="display:flex; gap:6px; margin-top:10px; flex-wrap:wrap;">
      <button class="secondary" onclick="voegVoorraadRegelToe()">+ Voorraad-regel</button>
      <button class="secondary" onclick="voegVrijeRegelToe()">+ Vrije regel (bijv. verzendkosten)</button>
    </div>

    <p style="margin-top:10px; font-weight:600;">Totaal: <span id="p-totaal">€ 0,00</span></p>

    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="postOpslaan()">Aanmaken</button>
    </div>
  </div>
</div>

<!-- ===== Modal: post afhandelen (betaling ontvangen/verricht) ===== -->
<div id="modal-post-afhandelen" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-post-afhandelen')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-post-afhandelen')">✕</button>
    <h2 id="afhandelen-titel">Post afhandelen</h2>
    <p style="color:var(--grey); font-size:13px;">Vink de regels aan die nu betaald zijn.</p>
    <div id="afhandelen-regels"></div>
    <p style="margin-top:10px; font-weight:600;">Te boeken: <span id="afhandelen-totaal">€ 0,00</span></p>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="postAfhandelenOpslaan()">Bevestigen</button>
    </div>
  </div>
</div>

<!-- ===== Modal: bestaande bijlage kiezen (voor een regel van gezamenlijke inkoop) ===== -->
<div id="modal-bijlage-kiezen" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-bijlage-kiezen')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-bijlage-kiezen')">✕</button>
    <h2>Kies een bestaande bijlage</h2>
    <input id="bk-zoek" placeholder="Zoeken op bestandsnaam…" oninput="renderBijlageKiezerLijst()" style="margin-bottom:8px;" />
    <div id="bijlage-kiezer-lijst" style="max-height:420px; overflow-y:auto;"></div>
  </div>
</div>

<!-- ===== Groot voorbeeldvenster bij hover over een grafiek-ballonnetje ===== -->
<div id="fin-voorbeeld-overlay" style="display:none; position:fixed; z-index:999; pointer-events:none; background:#111; border-radius:10px; box-shadow:0 8px 30px rgba(0,0,0,.35); overflow:hidden;">
  <img id="fin-voorbeeld-img" style="display:none; max-width:420px; max-height:420px; display:block;" />
  <iframe id="fin-voorbeeld-pdf" style="display:none; width:340px; height:476px; margin-top:-36px; clip-path:inset(36px 0 0 0); border:none;"></iframe>
</div>

<script>
const isFinancien = <?= $isFinancien ? 'true' : 'false' ?>;
const magVerifieren = <?= $magVerifieren ? 'true' : 'false' ?>;
const standaardCategorieen = <?= json_encode(TRANSACTIE_CATEGORIEEN, JSON_UNESCAPED_UNICODE) ?>;
const magDerving = <?= $magDerving ? 'true' : 'false' ?>;
const eigenUserId = <?= json_encode($eigenUserId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const eigenUserNaam = <?= json_encode($_SESSION['user_name'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let huidigeTransactieId = null;
let huidigAanvraagType = 'declaratie';
let huidigeAanvraagIdBewerken = null;
let huidigeBesluitId = null;
let huidigeBetaalId = null;
let bijlageT = [], bijlageA = [], bijlageP = [];
let relatiesData = [];
let postenData = [];
let voorraadDataFin = [];
let huidigeAfhandelPostId = null;

function toonView(v) {
  ['balans','boekhouding','aanvragen','posten','reroutes','bijlagen'].forEach(x => document.getElementById('view-' + x).style.display = x === v ? '' : 'none');
  if (v === 'bijlagen') laadBijlagenOverzicht();
}
function sluitModal(id) { document.getElementById(id).style.display = 'none'; }
function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

let datumformaatVolgorde = 'dmy';
function formatDatum(iso) {
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const jj = d.getFullYear();
  if (datumformaatVolgorde === 'mdy') return `${mm}-${dd}-${jj}`;
  if (datumformaatVolgorde === 'ymd') return `${jj}-${mm}-${dd}`;
  return `${dd}-${mm}-${jj}`;
}
async function laadInstellingenFinancien() {
  const res = await fetch('api_leden.php?action=instellingen_get', { cache: 'no-store' });
  const data = await res.json();
  datumformaatVolgorde = data.instellingen.datumformaat || 'dmy';
}
function euro(v) { return '€ ' + Number(v).toFixed(2).replace('.', ','); }

function bijlagenLinks(bijlagen) {
  if (!bijlagen || !bijlagen.length) return '—';
  return bijlagen.map((b, i) => {
    if (b.gearchiveerd) return `📎 ${escapeHtml(b.bestandsnaam || '')} <span class="badge grijs">alleen in back-up</span>`;
    return `<a href="${bijlageDownloadUrl(b)}" target="_blank">📎${bijlagen.length > 1 ? ' ' + (i + 1) : ''}</a>`;
  }).join(' ');
}

function bijlageDownloadUrl(b) {
  let params;
  if (b.kdrivePad) params = `kdrivePad=${encodeURIComponent(b.kdrivePad)}&mime=${encodeURIComponent(b.mimeType || 'application/octet-stream')}`;
  else if (b.driveFileId) params = `driveFileId=${encodeURIComponent(b.driveFileId)}&mime=${encodeURIComponent(b.mimeType || 'application/octet-stream')}`;
  else params = `pad=${encodeURIComponent(b.pad)}`;
  return `api_financien.php?action=bijlage_download&${params}`;
}

// ---- Bijlagen-overzicht ----------------------------------------------------

let bijlagenOverzichtData = [];
let huidigePreviewIndex = null;

async function laadBijlagenOverzicht() {
  const res = await fetch('api_financien.php?action=bijlagen_overzicht', { cache: 'no-store' });
  if (!res.ok) { document.getElementById('bijlagen-lijst').innerHTML = '<p style="color:var(--grey);">Geen toegang, of laden mislukt.</p>'; return; }
  const data = await res.json();
  bijlagenOverzichtData = data.bijlagen;
  document.getElementById('bijlagen-aantal').textContent = bijlagenOverzichtData.length + ' bestand(en)';
  renderBijlagenLijst();
}

async function losseBijlageUploaden(inputEl) {
  if (!inputEl.files.length) return;
  const statusEl = document.getElementById('los-bijlage-status');
  const resultaat = await uploadEenBestand(inputEl.files[0], statusEl);
  inputEl.value = '';
  if (!resultaat) return;
  const res = await fetch('api_financien.php?action=bijlage_los_aanmaken', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(resultaat),
  });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Opslaan mislukt.'); return; }
  statusEl.textContent = '✓ Toegevoegd — nu te koppelen via 🔗 bij een transactie/declaratie/etc.';
  await laadBijlagenOverzicht();
}

const bijlageTypeLabel = { transactie: 'Boekhouding', aanvraag: 'Declaratie/aanvraag', post: 'Doorverkoop', derving: 'Derving', los: 'Losse upload' };

function renderBijlagenLijst() {
  const zoekEl = document.getElementById('bijlagen-zoek');
  const zoek = zoekEl ? zoekEl.value.toLowerCase().trim() : '';
  const sortering = document.getElementById('bijlagen-sortering')?.value || 'naam-az';

  const sorteerFuncties = {
    'naam-az': (a, b) => a.origineel.bestandsnaam.localeCompare(b.origineel.bestandsnaam),
    'naam-za': (a, b) => b.origineel.bestandsnaam.localeCompare(a.origineel.bestandsnaam),
    'datum-nieuw': (a, b) => (b.origineel.datum || '').localeCompare(a.origineel.datum || ''),
    'datum-oud': (a, b) => (a.origineel.datum || '9999-99-99').localeCompare(b.origineel.datum || '9999-99-99'),
  };

  const zoekTekstVoor = (b) => b.bestandsnaam.toLowerCase() + ' ' + b.links.map(l => l.contextLabel).join(' ').toLowerCase();

  // Elk item onthoudt zijn oorspronkelijke index in bijlagenOverzichtData
  // (nodig voor alle knoppen/acties), ook nadat we hier filteren/sorteren.
  const items = bijlagenOverzichtData
    .map((b, i) => ({ origineel: b, origineelIndex: i }))
    .filter(({ origineel: b }) => !zoek || zoekTekstVoor(b).includes(zoek))
    .sort(sorteerFuncties[sortering] || sorteerFuncties['naam-az']);

  document.getElementById('bijlagen-lijst').innerHTML = items.map(({ origineel: b, origineelIndex: i }) => {
    const koppelingenTekst = b.links.map(l => (bijlageTypeLabel[l.parentType] || l.parentType) + ' — ' + l.contextLabel).join(' | ');
    const koppelBadge = b.links.length > 1 ? ` <span class="badge grijs" style="font-size:9.5px;" title="Gekoppeld aan ${b.links.length} plekken: ${escapeHtml(koppelingenTekst)}">×${b.links.length}</span>` : '';
    return `
    <div class="bijlage-rij" data-idx="${i}" style="padding:8px 0; border-bottom:1px solid var(--border); ${i === huidigePreviewIndex ? 'background:var(--primary-light-95);' : ''}" title="${escapeHtml(koppelingenTekst)}">
      <div style="display:flex; align-items:center; gap:6px;">
        <span style="flex:0 0 auto; cursor:pointer;" onclick="document.getElementById('bijlage-vervang-input-${i}').click()" title="Vervangen door een nieuw bestand">📎</span>
        <input type="file" id="bijlage-vervang-input-${i}" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" onchange="bijlageVervangen(${i}, this)" />
        <span id="bijlage-naam-weergave-${i}" onclick="toonBijlagePreview(${i})" ondblclick="startBijlageHernoemen(${i})" title="Klik voor voorbeeld, dubbelklik om te hernoemen" style="flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:13.5px; font-weight:600; cursor:pointer; padding:2px 0;">${escapeHtml(b.bestandsnaam)}</span>
        <input id="bijlage-naam-input-${i}" class="bijlage-naam-input" value="${escapeHtml(b.bestandsnaam)}" onblur="stopBijlageHernoemen(${i}, this.value)" onkeydown="if(event.key==='Enter') this.blur();" style="display:none; border:none; border-bottom:1px dashed var(--grey); background:transparent; font-size:13.5px; font-weight:600; padding:2px 0; flex:1; min-width:0;" />
        ${koppelBadge}
        <button type="button" class="secondary" style="flex:0 0 auto; padding:2px 7px; font-size:12px;" onclick="openBijlageSamenvoegen(${i})" title="Samenvoegen met een andere bijlage (bijv. dubbel geüpload)">🔗</button>
        <button type="button" class="secondary" style="flex:0 0 auto; padding:2px 7px; font-size:12px; color:#c9432f; border-color:#c9432f;" onclick="bijlageVerwijderen(${i})" title="Verwijderen">✕</button>
      </div>
    </div>`;
  }).join('') || '<p style="color:var(--grey);">Geen bijlagen gevonden.</p>';
}

function startBijlageHernoemen(i) {
  document.getElementById('bijlage-naam-weergave-' + i).style.display = 'none';
  const inputEl = document.getElementById('bijlage-naam-input-' + i);
  inputEl.style.display = 'block';
  inputEl.focus();
  inputEl.select();
}

function stopBijlageHernoemen(i, nieuweNaam) {
  document.getElementById('bijlage-naam-input-' + i).style.display = 'none';
  document.getElementById('bijlage-naam-weergave-' + i).style.display = '';
  if (nieuweNaam.trim() && nieuweNaam !== bijlagenOverzichtData[i].bestandsnaam) {
    bijlageHernoemen(i, nieuweNaam);
  }
}

function toonBijlagePreview(i) {
  // Highlight direct in de DOM aanpassen (niet de hele lijst herbouwen!) —
  // anders "verdwijnt" het element waarop je klikt vlak vóórdat een
  // eventuele dubbelklik geregistreerd kan worden, want bij een
  // dubbelklik vuren er eerst 2 losse click-events.
  const vorige = document.querySelector(`.bijlage-rij[data-idx="${huidigePreviewIndex}"]`);
  if (vorige) vorige.style.background = '';
  huidigePreviewIndex = i;
  const huidige = document.querySelector(`.bijlage-rij[data-idx="${i}"]`);
  if (huidige) huidige.style.background = 'var(--primary-light-95)';

  const b = bijlagenOverzichtData[i];
  const url = bijlageDownloadUrl(b);
  const isAfbeelding = /\.(jpe?g|png)$/i.test(b.bestandsnaam) || (b.mimeType || '').startsWith('image/');
  document.getElementById('bijlage-preview').innerHTML = isAfbeelding
    ? `<img src="${url}" style="max-width:100%; max-height:600px; object-fit:contain;" />`
    : `<iframe src="${url}" style="width:100%; height:600px; border:none;"></iframe>`;
}

async function bijlageVervangen(i, inputEl) {
  if (!inputEl.files.length) return;
  const resultaat = await uploadEenBestand(inputEl.files[0], {}); // geen zichtbaar statusveld nodig voor deze snelle actie
  inputEl.value = '';
  if (!resultaat) { alert('Upload mislukt.'); return; }
  const b = bijlagenOverzichtData[i];
  const res = await fetch('api_financien.php?action=bijlage_vervangen', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ links: b.links, ...resultaat }),
  });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Vervangen mislukt.'); return; }
  await laadBijlagenOverzicht();
}

async function bijlageHernoemen(i, nieuweNaam) {
  const b = bijlagenOverzichtData[i];
  if (!nieuweNaam.trim()) { alert('Naam mag niet leeg zijn.'); renderBijlagenLijst(); return; }
  const res = await fetch('api_financien.php?action=bijlage_hernoemen', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ links: b.links, bestandsnaam: nieuweNaam }),
  });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Hernoemen mislukt.'); renderBijlagenLijst(); return; }
  b.bestandsnaam = nieuweNaam;
  renderBijlagenLijst();
}

// ---- Balans ----------------------------------------------------

async function laadBalans() {
  const res = await fetch('api_financien.php?action=balans', { cache: 'no-store' });
  const data = await res.json();
  huidigStartsaldo = data.startsaldo;
  document.getElementById('bal-startsaldo').textContent = euro(data.startsaldo);
  document.getElementById('bal-inkomsten').textContent = euro(data.inkomsten);
  document.getElementById('bal-uitgaven').textContent = euro(data.uitgaven);
  document.getElementById('bal-saldo').textContent = euro(data.saldo);
  const entries = Object.entries(data.perCategorie).filter(([, bedrag]) => bedrag > 0).sort((a, b) => b[1] - a[1]);
  document.getElementById('bal-categorieen').innerHTML = entries.map(([cat, bedrag]) => `
    <div style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid var(--border); font-size:13.5px;">
      <span>${escapeHtml(cat)}</span><span>${euro(bedrag)}</span>
    </div>`).join('') || '<p style="color:var(--grey);">Nog geen uitgaven geboekt.</p>';
}

let huidigStartsaldo = 0;
async function startsaldoBewerken() {
  const invoer = prompt('Startsaldo (€) — het bedrag dat er al was vóórdat deze boekhouding begon:', huidigStartsaldo);
  if (invoer === null) return;
  const bedrag = parseFloat(invoer.replace(',', '.'));
  if (isNaN(bedrag)) { alert('Vul een geldig bedrag in.'); return; }
  await fetch('api_financien.php?action=startsaldo_bijwerken', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ startsaldo: bedrag }),
  });
  laadBalans();
}

// ---- Boekhouding ----------------------------------------------------

let transactiesData = [];
let aanvragenData = [];

// Kleine helpers om altijd dag-maand-jaar te tonen in formulieren (zoals in
// het overzicht), ongeacht wat de browser/OS als datumnotatie gebruikt —
// dat voorkomt dat iemand per ongeluk maand en dag verwisselt.
function datumNaarISO(nlDatum) {
  const m = (nlDatum || '').trim().match(/^(\d{1,2})-(\d{1,2})-(\d{4})$/);
  if (!m) return '';
  return `${m[3]}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}`;
}
function isoNaarDatum(iso) {
  const m = (iso || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m) return '';
  return `${m[3]}-${m[2]}-${m[1]}`;
}
function vandaagAlsDatum() {
  const d = new Date();
  return `${String(d.getDate()).padStart(2, '0')}-${String(d.getMonth() + 1).padStart(2, '0')}-${d.getFullYear()}`;
}

function renderRerouteBadge(rerouteId) {
  const r = reroutesData.find(x => x.id === rerouteId);
  if (!r) return '';
  return ` <span class="badge grijs" style="cursor:pointer;" onclick="toonRerouteGraph('${r.id}')" title="Onderdeel van gezamenlijke inkoop">${escapeHtml(r.nummer)}</span>`;
}

function renderReferentieBadge(t) {
  if (t.gekoppeldeRerouteId) return renderRerouteBadge(t.gekoppeldeRerouteId);
  if (t.gekoppeldePostId) {
    const p = postenData.find(x => x.id === t.gekoppeldePostId);
    return p ? ` <span class="badge grijs" style="cursor:pointer;" onclick="toonPostGraph('${p.id}')" title="Onderdeel van doorverkoop — klik voor overzicht">${escapeHtml(p.nummer)}</span>` : '';
  }
  if (t.gekoppeldeAanvraagId) {
    const a = aanvragenData.find(x => x.id === t.gekoppeldeAanvraagId);
    return a ? ` <span class="badge grijs" style="cursor:pointer;" onclick="toonAanvraagGraph('${a.id}')" title="Onderdeel van declaratie/bestelling-aanvraag — klik voor overzicht">${escapeHtml(a.nummer || '')}</span>` : '';
  }
  return '';
}

async function laadTransacties() {
  const res = await fetch('api_financien.php?action=transactie_list');
  const data = await res.json();
  transactiesData = data.transacties;
  document.getElementById('transacties-body').innerHTML = transactiesData.map(t => `
    <tr>
      <td>${formatDatum(t.datum)}</td>
      <td><span class="badge ${t.type === 'inkomst' ? 'groen' : 'rood'}">${t.type}</span></td>
      <td>${escapeHtml(t.omschrijving)}</td>
      <td>${escapeHtml(t.categorie || '—')}</td>
      <td>${euro(t.bedrag)}${renderReferentieBadge(t)}</td>
      <td>${bijlagenLinks(t.bijlagen)}</td>
      <td style="text-align:center; white-space:nowrap;">${renderVerificatieVinkje(t)}</td>
      <td style="white-space:nowrap;">
        ${isFinancien ? `<button class="secondary" onclick="openTransactieForm('${t.id}')">Bewerken</button> <button class="secondary" onclick="verwijderTransactie('${t.id}')">✕</button>` : ''}
      </td>
    </tr>`).join('') || '<tr><td colspan="8">Nog geen transacties.</td></tr>';
}

function renderVerificatieVinkje(t) {
  const titel = t.geverifieerd ? `Gecontroleerd door ${t.geverifieerdDoor} op ${formatDatum(t.geverifieerdOp)}` : 'Nog niet gecontroleerd';
  if (magVerifieren) {
    return `<input type="checkbox" ${t.geverifieerd ? 'checked' : ''} onchange="verifieerTransactie('${t.id}', this.checked)" title="${titel}" style="cursor:pointer; width:auto;" />`;
  }
  return t.geverifieerd
    ? `<span class="badge groen" title="${titel}">✓ gecontroleerd</span>`
    : `<span class="badge grijs" title="${titel}">—</span>`;
}

async function verifieerTransactie(id, geverifieerd) {
  await fetch('api_financien.php?action=transactie_verifieren&id=' + encodeURIComponent(id), {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ geverifieerd }),
  });
  laadTransacties();
}

function renderBijlagenBeheer(lijstElId, arr, verwijderFn) {
  document.getElementById(lijstElId).innerHTML = arr.map((b, i) => `
    <div style="display:flex; justify-content:space-between; align-items:center; padding:3px 0; font-size:12.5px;">
      <span>📎 ${escapeHtml(b.bestandsnaam || '')}</span>
      <button type="button" class="secondary" style="padding:1px 8px;" onclick="${verwijderFn}(${i})">✕</button>
    </div>`).join('');
}

async function uploadEenBestand(file, statusEl) {
  statusEl.textContent = 'Uploaden…';
  const fd = new FormData();
  fd.append('bestand', file);
  const res = await fetch('api_financien.php?action=bijlage_upload', { method: 'POST', credentials: 'same-origin', body: fd });
  const data = await res.json();
  if (!res.ok) { statusEl.textContent = data.error || 'Upload mislukt.'; return null; }
  statusEl.textContent = '✓ toegevoegd: ' + data.bestandsnaam;
  return data;
}

document.getElementById('t-bijlage').addEventListener('change', async (e) => {
  if (!e.target.files.length) return;
  const resultaat = await uploadEenBestand(e.target.files[0], document.getElementById('t-bijlage-status'));
  e.target.value = ''; // zodat je hetzelfde bestand nogmaals kunt kiezen indien nodig, en zodat 'change' opnieuw afgaat
  if (resultaat) { bijlageT.push(resultaat); renderBijlagenBeheer('t-bijlagen-lijst', bijlageT, 'verwijderBijlageT'); }
});
function verwijderBijlageT(i) { bijlageT.splice(i, 1); renderBijlagenBeheer('t-bijlagen-lijst', bijlageT, 'verwijderBijlageT'); }

function categorieDropdownGewijzigd() {
  const isOverig = document.getElementById('t-categorie-select').value === '__overig__';
  document.getElementById('t-categorie-overig').style.display = isOverig ? 'block' : 'none';
}

function vulCategorieDropdown(huidigeWaarde) {
  const select = document.getElementById('t-categorie-select');
  select.innerHTML = '<option value="">— geen categorie —</option>' +
    standaardCategorieen.map(c => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('') +
    '<option value="__overig__">Overig…</option>';

  if (huidigeWaarde && !standaardCategorieen.includes(huidigeWaarde)) {
    select.value = '__overig__';
    document.getElementById('t-categorie-overig').value = huidigeWaarde;
  } else {
    select.value = huidigeWaarde || '';
    document.getElementById('t-categorie-overig').value = '';
  }
  categorieDropdownGewijzigd();
}

function huidigeCategorieWaarde() {
  const select = document.getElementById('t-categorie-select');
  return select.value === '__overig__' ? document.getElementById('t-categorie-overig').value.trim() : select.value;
}

function vulReferentieOpties() {
  const type = document.getElementById('t-referentie-type').value;
  const select = document.getElementById('t-referentie-id');
  const lijsten = { reroute: reroutesData, post: postenData, aanvraag: aanvragenData };
  const labelFn = { reroute: r => r.nummer + ' — ' + r.titel, post: p => p.nummer + ' — ' + p.relatieNaam, aanvraag: a => (a.nummer || '?') + ' — ' + a.aanvragerNaam + ' (' + euro(a.bedrag) + ')' };
  if (!type) { select.innerHTML = '<option value="">— kies eerst een type —</option>'; return; }
  const lijst = lijsten[type] || [];
  select.innerHTML = '<option value="">— kies —</option>' + lijst.map(x => `<option value="${x.id}">${escapeHtml(labelFn[type](x))}</option>`).join('');
}

function vulReferentieVoorIn(t) {
  let type = '', refId = '';
  if (t?.gekoppeldeRerouteId) { type = 'reroute'; refId = t.gekoppeldeRerouteId; }
  else if (t?.gekoppeldePostId) { type = 'post'; refId = t.gekoppeldePostId; }
  else if (t?.gekoppeldeAanvraagId) { type = 'aanvraag'; refId = t.gekoppeldeAanvraagId; }
  document.getElementById('t-referentie-type').value = type;
  vulReferentieOpties();
  document.getElementById('t-referentie-id').value = refId;
}

function openTransactieForm(id) {
  const t = id ? transactiesData.find(x => x.id === id) : null;
  huidigeTransactieId = t ? t.id : null;
  bijlageT = t ? [...(t.bijlagen || [])] : [];
  document.getElementById('transactie-titel').textContent = t ? 'Transactie bewerken' : 'Transactie toevoegen';
  document.getElementById('t-type').value = t ? t.type : 'uitgave';
  document.getElementById('t-bedrag').value = t ? t.bedrag : '';
  document.getElementById('t-datum').value = t ? isoNaarDatum(t.datum) : vandaagAlsDatum();
  vulCategorieDropdown(t ? (t.categorie || '') : '');
  document.getElementById('t-omschrijving').value = t ? t.omschrijving : '';
  document.getElementById('t-bijlage').value = '';
  document.getElementById('t-bijlage-status').textContent = '';
  renderBijlagenBeheer('t-bijlagen-lijst', bijlageT, 'verwijderBijlageT');
  vulReferentieVoorIn(t);
  document.getElementById('modal-transactie').style.display = 'flex';
}

async function opslaanTransactie() {
  const isoDatum = datumNaarISO(document.getElementById('t-datum').value);
  if (!isoDatum) { alert('Vul de datum in als dd-mm-jjjj, bijv. 25-12-2026.'); return; }
  const body = {
    type: document.getElementById('t-type').value,
    bedrag: document.getElementById('t-bedrag').value,
    datum: isoDatum,
    categorie: huidigeCategorieWaarde(),
    omschrijving: document.getElementById('t-omschrijving').value,
    bijlagen: bijlageT,
    referentieType: document.getElementById('t-referentie-type').value,
    referentieId: document.getElementById('t-referentie-id').value,
  };
  if (!body.bedrag || !body.omschrijving.trim()) { alert('Bedrag en omschrijving zijn verplicht.'); return; }
  const url = huidigeTransactieId
    ? 'api_financien.php?action=transactie_update&id=' + encodeURIComponent(huidigeTransactieId)
    : 'api_financien.php?action=transactie_create';
  const res = await fetch(url, {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  if (!res.ok) { alert('Opslaan mislukt.'); return; }
  sluitModal('modal-transactie');
  laadTransacties();
  laadBalans();
}

async function verwijderTransactie(id) {
  if (!confirm('Deze transactie verwijderen?')) return;
  await fetch('api_financien.php?action=transactie_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadTransacties();
  laadBalans();
}

const transactieCsvInput = document.getElementById('transactieCsvInput');
if (transactieCsvInput) {
  transactieCsvInput.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (!file) return;
    Papa.parse(file, {
      header: true, skipEmptyLines: true,
      complete: async (results) => {
        if (!confirm(`${results.data.length} regels importeren?`)) return;
        await fetch('api_financien.php?action=transactie_bulk_import', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ rijen: results.data }) });
        transactieCsvInput.value = '';
        laadTransacties();
        laadBalans();
      },
    });
  });
}

// ---- Declaraties & aanvragen ----------------------------------------------------

async function laadAanvragen() {
  const res = await fetch('api_financien.php?action=aanvraag_list');
  const data = await res.json();
  aanvragenData = data.aanvragen;
  const statusBadge = { open: 'geel', goedgekeurd: 'groen', afgewezen: 'rood', uitbetaald: 'groen' };

  document.getElementById('aanvragen-body').innerHTML = aanvragenData.map(a => {
    const isEigen = a.aanvragerId === eigenUserId;
    const magBewerken = a.status === 'open' && (isEigen || isFinancien);
    let acties = '';
    if (magBewerken) acties += `<button class="secondary" onclick="openAanvraagForm(${escapeHtml(JSON.stringify(a.type))}, ${escapeHtml(JSON.stringify(a))})">Bewerken</button> `;
    if (isFinancien && a.status === 'open') acties += `<button class="secondary" onclick="openBesluitForm(${escapeHtml(JSON.stringify(a))})">Beoordelen</button> `;
    if (isEigen && a.status === 'goedgekeurd' && !a.betaalgegevens) acties += `<button class="secondary" onclick="openBetaalgegevensForm(${escapeHtml(JSON.stringify(a))})">Betaalgegevens doorgeven</button> `;
    if (isFinancien && a.status === 'goedgekeurd' && a.betaalgegevens) acties += `<button class="primary" onclick="markeerUitbetaald('${a.id}')">Markeer uitbetaald</button> `;
    if (isFinancien) acties += `<button class="secondary" onclick="verwijderAanvraag('${a.id}')">✕</button>`;

    return `<tr>
      <td style="cursor:pointer;" onclick="toonAanvraagGraph('${a.id}')">${escapeHtml(a.nummer || '—')}</td>
      <td>${a.type === 'declaratie' ? 'Declaratie' : 'Bestelling'}</td>
      <td>${escapeHtml(a.aanvragerNaam)}</td>
      <td>${escapeHtml(a.omschrijving)}${a.leverancier ? ' <span class="badge grijs">' + escapeHtml(a.leverancier) + '</span>' : ''} ${bijlagenLinks(a.bijlagen)}</td>
      <td>${euro(a.bedrag)}</td>
      <td><span class="badge ${statusBadge[a.status]}">${a.status}</span>${a.betaalgegevens && a.status === 'goedgekeurd' ? ' <span class="badge geel">gegevens ontvangen</span>' : ''}</td>
      <td style="white-space:nowrap;">${acties}</td>
    </tr>`;
  }).join('') || '<tr><td colspan="7">Nog geen aanvragen.</td></tr>';
}

function openAanvraagForm(type, bestaandeAanvraag) {
  huidigAanvraagType = type;
  huidigeAanvraagIdBewerken = bestaandeAanvraag ? bestaandeAanvraag.id : null;
  bijlageA = bestaandeAanvraag ? [...(bestaandeAanvraag.bijlagen || [])] : [];
  document.getElementById('aanvraag-formulier-velden').style.display = 'block';
  document.getElementById('aanvraag-succes').style.display = 'none';
  document.getElementById('aanvraag-titel').textContent = bestaandeAanvraag
    ? (type === 'declaratie' ? 'Declaratie bewerken' : 'Bestelling-aanvraag bewerken')
    : (type === 'declaratie' ? 'Declaratie indienen' : 'Bestelling-aanvraag indienen');
  document.getElementById('a-leverancier-label').style.display = type === 'bestelling_aanvraag' ? 'block' : 'none';
  document.getElementById('a-leverancier').style.display = type === 'bestelling_aanvraag' ? 'block' : 'none';
  document.getElementById('a-bedrag').value = bestaandeAanvraag ? bestaandeAanvraag.bedrag : '';
  document.getElementById('a-datum').value = bestaandeAanvraag?.datum ? isoNaarDatum(bestaandeAanvraag.datum) : vandaagAlsDatum();
  document.getElementById('a-leverancier').value = bestaandeAanvraag ? (bestaandeAanvraag.leverancier || '') : '';
  document.getElementById('a-omschrijving').value = bestaandeAanvraag ? bestaandeAanvraag.omschrijving : '';
  document.getElementById('a-bijlage').value = '';
  document.getElementById('a-bijlage-status').textContent = '';
  renderBijlagenBeheer('a-bijlagen-lijst', bijlageA, 'verwijderBijlageA');
  document.getElementById('modal-aanvraag').style.display = 'flex';
}

document.getElementById('a-bijlage').addEventListener('change', async (e) => {
  if (!e.target.files.length) return;
  const resultaat = await uploadEenBestand(e.target.files[0], document.getElementById('a-bijlage-status'));
  e.target.value = '';
  if (resultaat) { bijlageA.push(resultaat); renderBijlagenBeheer('a-bijlagen-lijst', bijlageA, 'verwijderBijlageA'); }
});
function verwijderBijlageA(i) { bijlageA.splice(i, 1); renderBijlagenBeheer('a-bijlagen-lijst', bijlageA, 'verwijderBijlageA'); }

async function opslaanAanvraag() {
  const bedrag = document.getElementById('a-bedrag').value;
  const omschrijving = document.getElementById('a-omschrijving').value;
  const isoDatum = datumNaarISO(document.getElementById('a-datum').value);
  if (!isoDatum) { alert('Vul de datum in als dd-mm-jjjj.'); return; }
  const body = {
    type: huidigAanvraagType,
    bedrag,
    datum: isoDatum,
    leverancier: document.getElementById('a-leverancier').value,
    omschrijving,
    bijlagen: bijlageA,
  };
  if (!body.bedrag || !body.omschrijving.trim()) { alert('Bedrag en omschrijving zijn verplicht.'); return; }

  const url = huidigeAanvraagIdBewerken
    ? 'api_financien.php?action=aanvraag_update&id=' + encodeURIComponent(huidigeAanvraagIdBewerken)
    : 'api_financien.php?action=aanvraag_create';
  const res = await fetch(url, {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Opslaan mislukt.'); return; }

  if (huidigeAanvraagIdBewerken) {
    sluitModal('modal-aanvraag');
    toonView('aanvragen');
    laadAanvragen();
    return;
  }

  const typeTekst = huidigAanvraagType === 'declaratie' ? 'declaratie' : 'bestelling-aanvraag';
  const portaalLink = window.location.origin + window.location.pathname + '?tab=aanvragen';
  const bericht = `📋 Nieuwe ${typeTekst} ingediend: € ${Number(bedrag).toFixed(2).replace('.', ',')} — ${omschrijving}\n\nBekijk in het portaal: ${portaalLink}`;
  document.getElementById('aanvraag-whatsapp-link').href = 'https://wa.me/?text=' + encodeURIComponent(bericht);

  document.getElementById('aanvraag-formulier-velden').style.display = 'none';
  document.getElementById('aanvraag-succes').style.display = 'block';
  toonView('aanvragen');
  laadAanvragen();
}

function sluitAanvraagModalNaSucces() {
  sluitModal('modal-aanvraag');
}

function openBesluitForm(a) {
  huidigeBesluitId = a.id;
  document.getElementById('besluit-info').textContent = a.aanvragerNaam + ' — ' + euro(a.bedrag) + ' — ' + a.omschrijving;
  document.getElementById('besluit-opmerking').value = '';
  document.getElementById('modal-besluit').style.display = 'flex';
}

async function besluitNemen(besluit) {
  await fetch('api_financien.php?action=aanvraag_besluit&id=' + encodeURIComponent(huidigeBesluitId), {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ besluit, opmerking: document.getElementById('besluit-opmerking').value }),
  });
  sluitModal('modal-besluit');
  laadAanvragen();
}

function openBetaalgegevensForm(a) {
  huidigeBetaalId = a.id;
  document.getElementById('bg-iban').value = '';
  document.getElementById('bg-tnv').value = '';
  document.getElementById('bg-opmerking').value = '';
  document.getElementById('modal-betaalgegevens').style.display = 'flex';
}

async function betaalgegevensOpslaan() {
  const body = { iban: document.getElementById('bg-iban').value, tenaamstelling: document.getElementById('bg-tnv').value, opmerking: document.getElementById('bg-opmerking').value };
  if (!body.iban.trim()) { alert('IBAN is verplicht.'); return; }
  await fetch('api_financien.php?action=aanvraag_betaalgegevens_indienen&id=' + encodeURIComponent(huidigeBetaalId), {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  sluitModal('modal-betaalgegevens');
  laadAanvragen();
}

async function markeerUitbetaald(id) {
  if (!confirm('Markeer als uitbetaald? Dit maakt automatisch een uitgave-transactie aan.')) return;
  await fetch('api_financien.php?action=aanvraag_uitbetaald&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadAanvragen();
  laadTransacties();
  laadBalans();
}

async function verwijderAanvraag(id) {
  if (!confirm('Deze aanvraag verwijderen?')) return;
  await fetch('api_financien.php?action=aanvraag_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadAanvragen();
}

const declaratieCsvInput = document.getElementById('declaratieCsvInput');
if (declaratieCsvInput) {
  declaratieCsvInput.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (!file) return;
    Papa.parse(file, {
      header: true, skipEmptyLines: true,
      complete: async (results) => {
        if (!confirm(`${results.data.length} regels importeren als afgehandelde declaraties?`)) return;
        await fetch('api_financien.php?action=aanvraag_bulk_import', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ rijen: results.data }) });
        declaratieCsvInput.value = '';
        laadAanvragen();
        laadTransacties();
        laadBalans();
      },
    });
  });
}

// ---- Relaties (debiteuren/crediteuren) ----------------------------------------------------

async function laadRelaties() {
  const res = await fetch('api_financien.php?action=relatie_list');
  const data = await res.json();
  relatiesData = data.relaties;
  const typeLabel = { debiteur: 'Debiteur', crediteur: 'Crediteur', beide: 'Debiteur & crediteur' };
  document.getElementById('relaties-body').innerHTML = relatiesData.map(r => `
    <tr>
      <td>${escapeHtml(r.naam)}</td>
      <td>${typeLabel[r.type]}</td>
      <td>${escapeHtml(r.contactpersoon || '—')}</td>
      <td>${escapeHtml(r.email || '—')}</td>
      <td>${isFinancien ? `<button class="secondary" onclick="openRelatieForm(${escapeHtml(JSON.stringify(r))})">Bewerken</button> <button class="secondary" onclick="relatieVerwijderen('${r.id}')">✕</button>` : ''}</td>
    </tr>`).join('') || '<tr><td colspan="5">Nog geen relaties.</td></tr>';
}

let huidigeRelatieId = null;
function openRelatieForm(r) {
  huidigeRelatieId = r?.id || null;
  document.getElementById('relatie-titel').textContent = r ? 'Relatie bewerken' : 'Relatie toevoegen';
  document.getElementById('r-naam').value = r?.naam || '';
  document.getElementById('r-type').value = r?.type || 'beide';
  document.getElementById('r-contactpersoon').value = r?.contactpersoon || '';
  document.getElementById('r-email').value = r?.email || '';
  document.getElementById('r-telefoon').value = r?.telefoon || '';
  document.getElementById('r-notitie').value = r?.notitie || '';
  document.getElementById('modal-relatie').style.display = 'flex';
}

async function relatieOpslaan() {
  const body = {
    id: huidigeRelatieId, naam: document.getElementById('r-naam').value, type: document.getElementById('r-type').value,
    contactpersoon: document.getElementById('r-contactpersoon').value, email: document.getElementById('r-email').value,
    telefoon: document.getElementById('r-telefoon').value, notitie: document.getElementById('r-notitie').value,
  };
  if (!body.naam.trim()) { alert('Naam is verplicht.'); return; }
  const res = await fetch('api_financien.php?action=relatie_upsert', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!res.ok) { alert('Opslaan mislukt.'); return; }
  sluitModal('modal-relatie');
  laadRelaties();
}

async function relatieVerwijderen(id) {
  if (!confirm('Deze relatie verwijderen?')) return;
  await fetch('api_financien.php?action=relatie_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadRelaties();
}

// ---- Doorverkoop & inkoop op rekening ----------------------------------------------------

async function laadVoorraadFin() {
  const res = await fetch('api_drukwerk.php?action=voorraad_list');
  const data = await res.json();
  voorraadDataFin = data.voorraad;
}

async function laadPosten() {
  const res = await fetch('api_financien.php?action=post_list');
  const data = await res.json();
  postenData = data.posten;
  const statusBadge = { open: 'geel', deels_afgehandeld: 'geel', afgehandeld: 'groen' };

  document.getElementById('posten-body').innerHTML = postenData.map(p => `
    <tr>
      <td style="cursor:pointer;" onclick="toonPostGraph('${p.id}')">${escapeHtml(p.nummer)}</td>
      <td>Doorverkoop</td>
      <td>${escapeHtml(p.relatieNaam)}</td>
      <td style="font-size:12.5px;">${p.regels.map(r => escapeHtml(r.omschrijving) + (r.prive ? ' <span class="badge grijs" style="font-size:9.5px;">privé</span>' : '')).join('<br>')}</td>
      <td>${euro(p.totaalbedrag)}</td>
      <td>${euro(p.totaalbedrag - p.afgehandeldBedrag)}</td>
      <td><span class="badge ${statusBadge[p.status]}">${p.status.replace('_', ' ')}</span></td>
      <td style="white-space:nowrap;">
        ${(isFinancien || p.createdBy === eigenUserNaam) && p.status === 'open' && p.afgehandeldBedrag === 0 ? `<button class="secondary" onclick="openPostForm(${escapeHtml(JSON.stringify(p))})">Bewerken</button> ` : ''}
        ${isFinancien && p.status !== 'afgehandeld' ? `<button class="secondary" onclick="openAfhandelenForm(${escapeHtml(JSON.stringify(p))})">Afhandelen</button> ` : ''}
        ${isFinancien ? `<button class="secondary" style="color:#c9432f; border-color:#c9432f;" onclick="verwijderPost('${p.id}', ${p.afgehandeldBedrag > 0})">✕</button>` : ''}
      </td>
    </tr>`).join('') || '<tr><td colspan="8">Nog geen doorverkopen.</td></tr>';
}

async function verwijderPost(id, isAfgehandeld) {
  const waarschuwing = isAfgehandeld
    ? 'Deze doorverkoop is (deels) afgehandeld — verwijderen haalt ook de bijbehorende inkomst-boeking(en) uit de boekhouding. Weet je het zeker?'
    : 'Deze doorverkoop verwijderen? De voorraadmutatie van de regels wordt teruggedraaid.';
  if (!confirm(waarschuwing)) return;
  await fetch('api_financien.php?action=post_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadPosten();
  laadTransacties();
  laadBalans();
}

let huidigePostId = null;

async function openPostForm(bestaandePost) {
  huidigePostId = bestaandePost ? bestaandePost.id : null;
  bijlageP = bestaandePost ? [...(bestaandePost.bijlagen || [])] : [];
  document.getElementById('post-titel').textContent = bestaandePost ? 'Doorverkoop bewerken — ' + bestaandePost.nummer : 'Doorverkoop aanmaken';
  document.getElementById('p-datum').value = bestaandePost?.datum ? isoNaarDatum(bestaandePost.datum) : vandaagAlsDatum();
  document.getElementById('p-factuur-velden').style.display = 'none';
  document.getElementById('p-factuurnummer').value = bestaandePost ? (bestaandePost.factuurnummer || '') : '';
  document.getElementById('p-bijlage').value = '';
  document.getElementById('p-bijlage-status').textContent = '';
  renderBijlagenBeheer('p-bijlagen-lijst', bijlageP, 'verwijderBijlageP');
  if (!voorraadDataFin.length) await laadVoorraadFin();
  document.getElementById('p-relatie').innerHTML = relatiesData
    .filter(r => r.type === 'beide' || r.type === 'debiteur')
    .map(r => `<option value="${r.id}"${bestaandePost && r.id === bestaandePost.relatieId ? ' selected' : ''}>${escapeHtml(r.naam)}</option>`).join('') || '<option value="">Voeg eerst een relatie toe</option>';

  document.getElementById('p-regels-lijst').innerHTML = '';
  if (bestaandePost) {
    bestaandePost.regels.forEach(regel => {
      if (regel.voorraadKoppeling) voegVoorraadRegelToe(regel.voorraadKoppeling);
      else voegVrijeRegelToe(regel);
    });
  }
  bijwerkenPostTotaal();
  document.getElementById('modal-post').style.display = 'flex';
}

document.getElementById('p-bijlage').addEventListener('change', async (e) => {
  if (!e.target.files.length) return;
  const resultaat = await uploadEenBestand(e.target.files[0], document.getElementById('p-bijlage-status'));
  e.target.value = '';
  if (resultaat) { bijlageP.push(resultaat); renderBijlagenBeheer('p-bijlagen-lijst', bijlageP, 'verwijderBijlageP'); }
});
function verwijderBijlageP(i) { bijlageP.splice(i, 1); renderBijlagenBeheer('p-bijlagen-lijst', bijlageP, 'verwijderBijlageP'); }

function voegVoorraadRegelToe(bestaandeKoppeling) {
  const opties = voorraadDataFin.map(v => `<option value="${escapeHtml(v.naam)}" data-waarde="${v.waardePerStuk ?? 0}" ${bestaandeKoppeling?.itemNaam === v.naam ? 'selected' : ''}>${escapeHtml(v.naam)} (voorraad: ${v.huidigeVoorraad})</option>`).join('');
  const idx = Date.now() + Math.random();
  // Bij bewerken: de opgeslagen prijs-per-stuk altijd exact reproduceren
  // (als "aangepaste prijs", ongeacht of het toen de standaardprijs was) —
  // zo verandert bewerken nooit stilzwijgend het bedrag van een oude regel
  // omdat de voorraadprijs inmiddels is gewijzigd.
  const heeftBestaande = !!bestaandeKoppeling;
  const html = `
    <div class="checkbox-row" data-regel-type="voorraad" data-regel-id="${idx}">
      <select class="p-regel-item" onchange="bijwerkenPostTotaal()" style="flex:2;">${opties}</select>
      <input type="number" class="p-regel-aantal" min="1" value="${bestaandeKoppeling?.aantal ?? 1}" style="width:60px;" onchange="bijwerkenPostTotaal()" />
      <label style="display:flex; align-items:center; gap:2px; font-size:10.5px; color:var(--grey); white-space:nowrap; margin:0;">
        <input type="checkbox" class="p-regel-prijs-aan" style="width:auto;" ${heeftBestaande ? 'checked' : ''} onchange="this.closest('[data-regel-id]').querySelector('.p-regel-prijs-veld').style.display = this.checked ? 'inline-block' : 'none'; bijwerkenPostTotaal();" />aangepaste prijs
      </label>
      <input type="number" class="p-regel-prijs-veld" min="0" step="0.0001" placeholder="€/stuk" value="${bestaandeKoppeling?.waardePerStuk ?? ''}" style="width:75px; display:${heeftBestaande ? 'inline-block' : 'none'};" onchange="bijwerkenPostTotaal()" />
      <span class="p-regel-subtotaal" style="width:80px; text-align:right; font-size:12.5px;">€ 0,00</span>
      <button type="button" class="secondary" onclick="this.closest('[data-regel-id]').remove(); bijwerkenPostTotaal();">✕</button>
    </div>`;
  document.getElementById('p-regels-lijst').insertAdjacentHTML('beforeend', html);
  bijwerkenPostTotaal();
}

function voegVrijeRegelToe(bestaandeRegel) {
  const idx = Date.now();
  document.getElementById('p-regels-lijst').insertAdjacentHTML('beforeend', `
    <div class="checkbox-row" data-regel-type="vrij" data-regel-id="${idx}">
      <input class="p-regel-omschrijving" placeholder="Omschrijving (bijv. verzendkosten)" value="${escapeHtml(bestaandeRegel?.omschrijving || '')}" style="flex:2;" />
      <input type="number" class="p-regel-bedrag" min="0" step="0.01" placeholder="€" value="${bestaandeRegel?.bedrag ?? ''}" style="width:90px;" onchange="bijwerkenPostTotaal()" />
      <label style="display:flex; align-items:center; gap:2px; font-size:10.5px; color:var(--grey); white-space:nowrap; margin:0;" title="Deze kost wordt buiten de clubkas om geregeld (bijv. iemand betaalt en int het zelf rechtstreeks) — telt niet mee in wat het district nog moet innen.">
        <input type="checkbox" class="p-regel-prive" style="width:auto;" ${bestaandeRegel?.prive ? 'checked' : ''} onchange="bijwerkenPostTotaal()" />privé afgehandeld
      </label>
      <button type="button" class="secondary" onclick="this.closest('[data-regel-id]').remove(); bijwerkenPostTotaal();">✕</button>
    </div>`);
  bijwerkenPostTotaal();
}

function bijwerkenPostTotaal() {
  let totaal = 0;
  document.querySelectorAll('#p-regels-lijst [data-regel-id]').forEach(rij => {
    if (rij.dataset.regelType === 'voorraad') {
      const aantal = parseInt(rij.querySelector('.p-regel-aantal').value, 10) || 0;
      const prijsAan = rij.querySelector('.p-regel-prijs-aan')?.checked;
      const waarde = prijsAan
        ? (parseFloat(rij.querySelector('.p-regel-prijs-veld').value) || 0)
        : parseFloat(rij.querySelector('.p-regel-item')?.selectedOptions[0]?.dataset.waarde || 0);
      const subtotaal = aantal * waarde;
      rij.querySelector('.p-regel-subtotaal').textContent = euro(subtotaal);
      totaal += subtotaal;
    } else {
      const privé = rij.querySelector('.p-regel-prive')?.checked;
      const regelBedrag = parseFloat(rij.querySelector('.p-regel-bedrag').value) || 0;
      if (!privé) totaal += regelBedrag;
    }
  });
  document.getElementById('p-totaal').textContent = euro(totaal);
}

async function postOpslaan() {
  const isoDatum = datumNaarISO(document.getElementById('p-datum').value);
  if (!isoDatum) { alert('Vul de datum in als dd-mm-jjjj.'); return; }
  const regels = [...document.querySelectorAll('#p-regels-lijst [data-regel-id]')].map(rij => {
    if (rij.dataset.regelType === 'voorraad') {
      const aantal = parseInt(rij.querySelector('.p-regel-aantal').value, 10) || 0;
      const naam = rij.querySelector('.p-regel-item').value;
      const prijsAan = rij.querySelector('.p-regel-prijs-aan')?.checked;
      const koppeling = { itemNaam: naam, aantal };
      if (prijsAan) koppeling.aangepastePrijsPerStuk = parseFloat(rij.querySelector('.p-regel-prijs-veld').value) || 0;
      return { voorraadKoppeling: koppeling };
    }
    return { omschrijving: rij.querySelector('.p-regel-omschrijving').value, bedrag: parseFloat(rij.querySelector('.p-regel-bedrag').value) || 0, prive: rij.querySelector('.p-regel-prive')?.checked || false };
  });

  const body = {
    relatieId: document.getElementById('p-relatie').value, regels, datum: isoDatum,
    factuurnummer: document.getElementById('p-factuurnummer').value, bijlagen: bijlageP,
  };
  if (!body.relatieId) { alert('Kies een relatie.'); return; }
  const url = huidigePostId
    ? 'api_financien.php?action=post_update&id=' + encodeURIComponent(huidigePostId)
    : 'api_financien.php?action=post_create';
  const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Opslaan mislukt.'); return; }
  sluitModal('modal-post');
  laadPosten();
  laadTransacties();
  laadBalans();
  alert((huidigePostId ? 'Bijgewerkt: ' : 'Aangemaakt: ') + data.post.nummer);
}

function openAfhandelenForm(p) {
  huidigeAfhandelPostId = p.id;
  document.getElementById('afhandelen-titel').textContent = p.nummer + ' — ' + p.relatieNaam;
  document.getElementById('afhandelen-regels').innerHTML = p.regels.map((r, i) => `
    <div class="checkbox-row">
      <input type="checkbox" class="afh-regel" value="${i}" onchange="bijwerkenAfhandelTotaal()" checked />
      <label style="margin:0; flex:1;">${escapeHtml(r.omschrijving)}</label>
      <span>${euro(r.bedrag)}</span>
    </div>`).join('');
  window._afhandelRegels = p.regels;
  bijwerkenAfhandelTotaal();
  document.getElementById('modal-post-afhandelen').style.display = 'flex';
}

function bijwerkenAfhandelTotaal() {
  let totaal = 0;
  document.querySelectorAll('.afh-regel:checked').forEach(cb => { totaal += window._afhandelRegels[parseInt(cb.value, 10)].bedrag; });
  document.getElementById('afhandelen-totaal').textContent = euro(totaal);
}

async function postAfhandelenOpslaan() {
  const regelIndexen = [...document.querySelectorAll('.afh-regel:checked')].map(cb => parseInt(cb.value, 10));
  if (!regelIndexen.length) { alert('Kies minstens 1 regel.'); return; }
  const res = await fetch('api_financien.php?action=post_afhandelen&id=' + encodeURIComponent(huidigeAfhandelPostId), {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ regelIndexen }),
  });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Afhandelen mislukt.'); return; }
  sluitModal('modal-post-afhandelen');
  laadPosten();
  laadTransacties();
  laadBalans();
}

// ---- Derving ----------------------------------------------------

let bijlageD = [];

async function laadDerving() {
  const res = await fetch('api_financien.php?action=derving_list');
  const data = await res.json();
  const redenLabel = { miskoop: 'Miskoop', verloren_zending: 'Verloren zending', schade: 'Schade', overig: 'Overig' };
  document.getElementById('derving-body').innerHTML = data.derving.map(d => `
    <tr>
      <td>${formatDatum(d.createdAt)}</td>
      <td>${escapeHtml(d.itemNaam)}</td>
      <td>${d.aantal}</td>
      <td><span class="badge rood">${redenLabel[d.reden]}</span></td>
      <td>${euro(d.verlies)}</td>
      <td>${escapeHtml(d.toelichting || '—')} ${bijlagenLinks(d.bijlagen)}</td>
      <td>${magDerving ? `<button class="secondary" onclick="dervingVerwijderen('${d.id}')">✕</button>` : ''}</td>
    </tr>`).join('') || '<tr><td colspan="7">Nog geen derving geregistreerd.</td></tr>';
}

async function openDervingForm() {
  bijlageD = [];
  if (!voorraadDataFin.length) await laadVoorraadFin();
  document.getElementById('d-item').innerHTML = voorraadDataFin.map(v => `<option value="${escapeHtml(v.naam)}" data-waarde="${v.waardePerStuk ?? 0}" data-voorraad="${v.huidigeVoorraad}">${escapeHtml(v.naam)} (voorraad: ${v.huidigeVoorraad})</option>`).join('');
  document.getElementById('d-aantal').value = 1;
  document.getElementById('d-reden').value = 'schade';
  document.getElementById('d-toelichting').value = '';
  document.getElementById('d-bijlage').value = '';
  document.getElementById('d-bijlage-status').textContent = '';
  renderBijlagenBeheer('d-bijlagen-lijst', bijlageD, 'verwijderBijlageD');
  bijwerkenDervingPreview();
  document.getElementById('modal-derving').style.display = 'flex';
}

document.getElementById('d-bijlage').addEventListener('change', async (e) => {
  if (!e.target.files.length) return;
  const resultaat = await uploadEenBestand(e.target.files[0], document.getElementById('d-bijlage-status'));
  e.target.value = '';
  if (resultaat) { bijlageD.push(resultaat); renderBijlagenBeheer('d-bijlagen-lijst', bijlageD, 'verwijderBijlageD'); }
});
function verwijderBijlageD(i) { bijlageD.splice(i, 1); renderBijlagenBeheer('d-bijlagen-lijst', bijlageD, 'verwijderBijlageD'); }

function bijwerkenDervingPreview() {
  const sel = document.getElementById('d-item').selectedOptions[0];
  if (!sel) return;
  const waarde = parseFloat(sel.dataset.waarde) || 0;
  const aantal = parseInt(document.getElementById('d-aantal').value, 10) || 0;
  document.getElementById('d-verlies-preview').textContent = `Verlies: ${euro(aantal * waarde)} (van ${sel.dataset.voorraad} op voorraad)`;
}
document.addEventListener('change', (e) => {
  if (e.target.id === 'd-item' || e.target.id === 'd-aantal') bijwerkenDervingPreview();
});

async function dervingOpslaan() {
  const body = {
    itemNaam: document.getElementById('d-item').value,
    aantal: document.getElementById('d-aantal').value,
    reden: document.getElementById('d-reden').value,
    toelichting: document.getElementById('d-toelichting').value,
    bijlagen: bijlageD,
  };
  const res = await fetch('api_financien.php?action=derving_create', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Registreren mislukt.'); return; }
  sluitModal('modal-derving');
  laadDerving();
  laadTransacties();
  laadBalans();
}

async function dervingVerwijderen(id) {
  if (!confirm('Deze dervingmelding verwijderen? Let op: de gekoppelde uitgave-transactie en voorraadmutatie worden hierbij NIET automatisch teruggedraaid.')) return;
  await fetch('api_financien.php?action=derving_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadDerving();
}

// ---- Opslagbeheer ----------------------------------------------------

async function laadOpslagStatus() {
  const el = document.getElementById('opslag-status');
  if (!el) return; // niet-financiën-rol ziet dit blok niet
  const res = await fetch('api_financien.php?action=opslag_status');
  if (!res.ok) { el.textContent = 'Kon opslagstatus niet ophalen.'; return; }
  const data = await res.json();
  if (data.limietMB > 0) {
    const pct = Math.min(100, Math.round((data.gebruiktMB / data.limietMB) * 100));
    el.textContent = `${data.gebruiktMB} MB van ${data.limietMB} MB gebruikt (${pct}%)`;
    const balk = document.getElementById('opslag-balk');
    balk.style.width = pct + '%';
    balk.style.background = pct >= 90 ? '#c9432f' : (pct >= 70 ? '#d4a417' : 'var(--primary)');
  } else {
    el.textContent = `${data.gebruiktMB} MB gebruikt (geen limiet ingesteld)`;
  }
}

function opslagBackupDownloaden() {
  window.location.href = 'api_financien.php?action=bijlagen_backup_downloaden';
}

async function opslagWissenNaBackup() {
  if (!confirm('Heb je de back-up-zip al gedownload en veilig bewaard?\n\nAls je op OK klikt, worden alle lokaal opgeslagen bijlagen permanent van de server verwijderd. De administratie (bedragen, omschrijvingen) blijft staan, maar de bestanden zelf zijn dan alleen nog in de back-up te vinden.')) return;
  const res = await fetch('api_financien.php?action=bijlagen_wissen_na_backup', { method: 'POST', credentials: 'same-origin' });
  const data = await res.json();
  alert(data.verwijderd + ' bestand(en) verwijderd van de server.');
  laadOpslagStatus();
  laadTransacties();
  laadAanvragen();
  laadPosten();
  laadDerving();
}

async function opslagHerstellenVanuitBackup(inputEl) {
  if (!inputEl.files.length) return;
  if (!confirm('Bijlagen uit deze back-up terugzetten? Ze worden weer gekoppeld aan dezelfde transactie/declaratie/etc. als voorheen.')) { inputEl.value = ''; return; }
  const statusEl = document.getElementById('opslag-herstel-status');
  statusEl.textContent = 'Bezig met herstellen…';
  const fd = new FormData();
  fd.append('backup', inputEl.files[0]);
  const res = await fetch('api_financien.php?action=bijlagen_herstellen_vanuit_backup', { method: 'POST', credentials: 'same-origin', body: fd });
  const data = await res.json();
  inputEl.value = '';
  if (!res.ok) { statusEl.textContent = ''; alert(data.error || 'Herstellen mislukt.'); return; }
  let bericht = `✓ ${data.hersteld} bijlage(n) hersteld.`;
  if (data.nietGevonden?.length) {
    bericht += ` ${data.nietGevonden.length} niet hersteld:\n- ` + data.nietGevonden.join('\n- ');
  }
  statusEl.textContent = bericht;
  laadOpslagStatus();
  laadTransacties();
  laadAanvragen();
  laadPosten();
  laadDerving();
  if (bijlagenOverzichtData.length) laadBijlagenOverzicht();
}

// ---- Gezamenlijke inkoop ("follow the money") ----------------------------------------------------

let reroutesData = [];

let huidigeRerouteId = null;

async function laadReroutes() {
  const res = await fetch('api_financien.php?action=reroute_list');
  const data = await res.json();
  reroutesData = data.reroutes;
  laadTransacties(); // opnieuw renderen zodat de GI-badges nu kunnen verschijnen
  document.getElementById('reroutes-body').innerHTML = reroutesData.map(r => {
    const totaalUit = r.totaalAankopen + (r.totaalBijkomendeKosten || 0);
    const verschil = Math.round((r.totaalBijdragen - totaalUit) * 100) / 100;
    const aantalUit = r.aankopen.length + (r.bijkomendeKosten?.length || 0);
    return `<tr>
      <td style="cursor:pointer;" onclick="toonRerouteGraph(${escapeHtml(JSON.stringify(r.id))})">${escapeHtml(r.nummer)}</td>
      <td style="cursor:pointer;" onclick="toonRerouteGraph(${escapeHtml(JSON.stringify(r.id))})">${escapeHtml(r.titel)}</td>
      <td style="cursor:pointer;" onclick="toonRerouteGraph(${escapeHtml(JSON.stringify(r.id))})">${euro(r.totaalBijdragen)} <span style="color:var(--grey); font-size:11.5px;">(${r.bijdragen.length}x)</span></td>
      <td style="cursor:pointer;" onclick="toonRerouteGraph(${escapeHtml(JSON.stringify(r.id))})">${euro(totaalUit)} <span style="color:var(--grey); font-size:11.5px;">(${aantalUit}x)</span></td>
      <td style="cursor:pointer;" onclick="toonRerouteGraph(${escapeHtml(JSON.stringify(r.id))})">${verschil === 0 ? '<span class="badge groen">sluitend</span>' : `<span class="badge ${verschil > 0 ? 'geel' : 'rood'}">${euro(Math.abs(verschil))} ${verschil > 0 ? 'over' : 'tekort'}</span>`}</td>
      <td style="white-space:nowrap;">
        ${isFinancien ? `<button class="secondary" onclick="openRerouteForm(${escapeHtml(JSON.stringify(r))})">Bewerken</button> <button class="secondary" onclick="verwijderReroute('${r.id}')">✕</button>` : ''}
      </td>
    </tr>`;
  }).join('') || '<tr><td colspan="6">Nog geen gezamenlijke inkopen.</td></tr>';
}

async function openRerouteForm(bestaandeReroute) {
  await laadGebruikersLijst();
  huidigeRerouteId = bestaandeReroute ? bestaandeReroute.id : null;
  document.getElementById('ri-titel-kop').textContent = bestaandeReroute ? 'Gezamenlijke inkoop bewerken — ' + bestaandeReroute.nummer : 'Nieuwe gezamenlijke inkoop';
  document.getElementById('ri-titel').value = bestaandeReroute ? bestaandeReroute.titel : '';
  document.getElementById('ri-datum').value = bestaandeReroute?.datum ? isoNaarDatum(bestaandeReroute.datum) : vandaagAlsDatum();
  document.getElementById('ri-bijdragen-lijst').innerHTML = '';
  document.getElementById('ri-aankopen-lijst').innerHTML = '';
  document.getElementById('ri-bijkomendeKosten-lijst').innerHTML = '';

  if (bestaandeReroute) {
    bestaandeReroute.bijdragen.forEach(b => voegRerouteRegelToe('bijdragen', b));
    bestaandeReroute.aankopen.forEach(a => voegRerouteRegelToe('aankopen', a));
    (bestaandeReroute.bijkomendeKosten || []).forEach(k => voegRerouteRegelToe('bijkomendeKosten', k));
  } else {
    voegRerouteRegelToe('bijdragen');
    voegRerouteRegelToe('aankopen');
  }
  bijwerkenRerouteTotalen();
  document.getElementById('modal-reroute').style.display = 'flex';
}

let gebruikersLijst = [];
async function laadGebruikersLijst() {
  if (gebruikersLijst.length) return;
  const res = await fetch('api_financien.php?action=gebruikers_lijst');
  const data = await res.json();
  gebruikersLijst = data.gebruikers;
}

function voegRerouteRegelToe(soort, bestaandeRegel) {
  const idx = Date.now() + Math.random();
  const placeholders = {
    bijdragen: 'Wie legt in (bijv. Regio Noord)',
    aankopen: 'Waar naartoe (bijv. Onlineprinters.nl)',
    bijkomendeKosten: 'Omschrijving (bijv. verzendkosten naar Regio Noord)',
  };
  const bestaandeBijlageJson = bestaandeRegel?.bijlagen?.[0] ? JSON.stringify(bestaandeRegel.bijlagen[0]).replace(/"/g, '&quot;') : '';
  const gefactureerdOpties = gebruikersLijst.map(u => `<option value="${u.id}" ${bestaandeRegel?.gekoppeldeAanvraagId && bestaandeRegel?.gefactureerdDoor === u.naam ? 'selected' : ''}>${escapeHtml(u.naam)}</option>`).join('');
  const gefactureerdDoorVeld = soort === 'bijkomendeKosten' ? `
      <input class="ri-regel-gefactureerd" placeholder="Gefactureerd/voorgeschoten door (vrije tekst)" value="${escapeHtml(bestaandeRegel?.gefactureerdDoor || '')}" style="flex:1.2;" />
      <select class="ri-regel-voorschot-user" style="flex:1.1;" title="Kies een geregistreerd lid als dit een vergoedbaar voorschot is — maakt automatisch een declaratie voor die persoon">
        <option value="">— geen declaratie —</option>
        ${gefactureerdOpties}
      </select>` : '';
  const voorschotBadge = bestaandeRegel?.gekoppeldeAanvraagId
    ? `<span class="badge geel" style="white-space:nowrap;" title="Loopt al als declaratie — blijft bij opnieuw opslaan ongewijzigd" data-bestaande-aanvraag-id="${bestaandeRegel.gekoppeldeAanvraagId}">${escapeHtml(bestaandeRegel.aanvraagNummer || 'declaratie')}</span>`
    : '';
  const eigenInlegVeld = soort === 'bijdragen'
    ? `<label style="display:flex; align-items:center; gap:3px; font-size:11px; color:var(--grey); white-space:nowrap; margin:0;"><input type="checkbox" class="ri-regel-eigeninleg" style="width:auto;" ${bestaandeRegel?.eigenInleg ? 'checked' : ''} />eigen inleg</label>`
    : '';
  document.getElementById('ri-' + soort + '-lijst').insertAdjacentHTML('beforeend', `
    <div class="checkbox-row" data-regel-id="${idx}">
      <input class="ri-regel-naam" placeholder="${placeholders[soort]}" value="${escapeHtml(bestaandeRegel?.naam || '')}" style="flex:2;" />
      <input type="number" class="ri-regel-bedrag" min="0" step="0.01" placeholder="€" value="${bestaandeRegel?.bedrag ?? ''}" style="width:90px;" onchange="bijwerkenRerouteTotalen()" ${bestaandeRegel?.gekoppeldeAanvraagId ? 'disabled title="Bedrag staat al vast in de gekoppelde declaratie"' : ''} />
      ${gefactureerdDoorVeld}
      ${voorschotBadge}
      ${eigenInlegVeld}
      <input type="file" class="ri-regel-bijlage" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" onchange="rerouteRegelBijlageUpload(this)" data-geupload-json="${bestaandeBijlageJson}" />
      <button type="button" class="secondary" onclick="this.previousElementSibling.click()" title="Nieuwe bijlage uploaden">📎${bestaandeRegel?.bijlagen?.length ? ' ✓' : ''}</button>
      <button type="button" class="secondary" onclick="openBijlageKiezer('${idx}')" title="Bestaande bijlage selecteren">🔗</button>
      <button type="button" class="secondary" onclick="this.closest('[data-regel-id]').remove(); bijwerkenRerouteTotalen();">✕</button>
    </div>
    <div class="ri-regel-bijlage-status" style="font-size:11px; color:var(--grey); margin:-4px 0 4px 2px;"></div>`);
  bijwerkenRerouteTotalen();
}

async function rerouteRegelBijlageUpload(inputEl) {
  const statusEl = inputEl.closest('[data-regel-id]').nextElementSibling;
  if (!inputEl.files.length) return;
  const resultaat = await uploadEenBestand(inputEl.files[0], statusEl);
  if (resultaat) inputEl.dataset.geuploadJson = JSON.stringify(resultaat);
}

// ---- Bestaande bijlage koppelen aan een regel (i.p.v. opnieuw uploaden) ----------------------------------------------------

let bijlageKiezerModus = 'regel'; // 'regel' | 'samenvoegen' | 'array'
let bijlageKiezerDoelRegelId = null;
let bijlageKiezerSamenvoegDoelIndex = null;
let bijlageKiezerArraySleutel = null;

async function openBijlageKiezer(regelId) {
  bijlageKiezerModus = 'regel';
  bijlageKiezerDoelRegelId = regelId;
  if (!bijlagenOverzichtData.length) await laadBijlagenOverzichtStil();
  document.getElementById('bk-zoek').value = '';
  renderBijlageKiezerLijst();
  document.getElementById('modal-bijlage-kiezen').style.display = 'flex';
}

function openBijlageSamenvoegen(index) {
  bijlageKiezerModus = 'samenvoegen';
  bijlageKiezerSamenvoegDoelIndex = index;
  document.getElementById('bk-zoek').value = '';
  renderBijlageKiezerLijst();
  document.getElementById('modal-bijlage-kiezen').style.display = 'flex';
}

// Bestaande bijlage koppelen aan een van de 4 formulieren die meerdere
// bijlagen ondersteunen (transactie/declaratie/doorverkoop/derving) — die
// gebruiken elk hun eigen array + weergave-lijst, maar op dezelfde manier.
const bijlageArrayConfig = {
  T: { lijstElId: 't-bijlagen-lijst', verwijderFn: 'verwijderBijlageT' },
  A: { lijstElId: 'a-bijlagen-lijst', verwijderFn: 'verwijderBijlageA' },
  P: { lijstElId: 'p-bijlagen-lijst', verwijderFn: 'verwijderBijlageP' },
  D: { lijstElId: 'd-bijlagen-lijst', verwijderFn: 'verwijderBijlageD' },
};
function bijlageArrayVoor(sleutel) {
  if (sleutel === 'T') return bijlageT;
  if (sleutel === 'A') return bijlageA;
  if (sleutel === 'P') return bijlageP;
  if (sleutel === 'D') return bijlageD;
  return null;
}

async function openBijlageKiezerVoorArray(sleutel) {
  bijlageKiezerModus = 'array';
  bijlageKiezerArraySleutel = sleutel;
  if (!bijlagenOverzichtData.length) await laadBijlagenOverzichtStil();
  document.getElementById('bk-zoek').value = '';
  renderBijlageKiezerLijst();
  document.getElementById('modal-bijlage-kiezen').style.display = 'flex';
}

function kiesBestaandeBijlageVoorArray(bijlageIndex) {
  const b = bijlagenOverzichtData[bijlageIndex];
  const arr = bijlageArrayVoor(bijlageKiezerArraySleutel);
  const config = bijlageArrayConfig[bijlageKiezerArraySleutel];
  if (!b || !arr || !config) return;
  arr.push({ bestandsnaam: b.bestandsnaam, pad: b.pad, driveFileId: b.driveFileId, kdrivePad: b.kdrivePad, mimeType: b.mimeType });
  renderBijlagenBeheer(config.lijstElId, arr, config.verwijderFn);
  sluitModal('modal-bijlage-kiezen');
}

// Laadt de bijlagenlijst zonder de UI van de Bijlagen-tab zelf aan te raken
// (die kan immers dicht staan terwijl je dit vanuit een ander formulier opent).
async function laadBijlagenOverzichtStil() {
  const res = await fetch('api_financien.php?action=bijlagen_overzicht', { cache: 'no-store' });
  if (!res.ok) return;
  const data = await res.json();
  bijlagenOverzichtData = data.bijlagen;
}

function renderBijlageKiezerLijst() {
  const zoek = document.getElementById('bk-zoek').value.toLowerCase();
  // Bij samenvoegen kan een bijlage uiteraard niet met zichzelf samengevoegd worden.
  const lijst = bijlagenOverzichtData
    .map((b, i) => ({ b, i }))
    .filter(({ b, i }) => (bijlageKiezerModus !== 'samenvoegen' || i !== bijlageKiezerSamenvoegDoelIndex) && (!zoek || b.bestandsnaam.toLowerCase().includes(zoek) || b.links.some(l => l.contextLabel.toLowerCase().includes(zoek))));
  const klikFn = bijlageKiezerModus === 'samenvoegen' ? 'bevestigSamenvoegen' : (bijlageKiezerModus === 'array' ? 'kiesBestaandeBijlageVoorArray' : 'kiesBestaandeBijlageVoorRegel');
  document.getElementById('bijlage-kiezer-lijst').innerHTML = lijst.map(({ b, i }) => `
    <div style="padding:8px 4px; border-bottom:1px solid var(--border); cursor:pointer;" onclick="${klikFn}(${i})">
      <strong style="font-size:13px;">📎 ${escapeHtml(b.bestandsnaam)}</strong>
      <div style="font-size:11px; color:var(--grey);">${b.links.map(l => escapeHtml(bijlageTypeLabel[l.parentType] || l.parentType) + ' — ' + escapeHtml(l.contextLabel)).join(' | ')}</div>
    </div>`).join('') || '<p style="color:var(--grey);">Geen bijlagen gevonden.</p>';
}

function kiesBestaandeBijlageVoorRegel(bijlageIndex) {
  const b = bijlagenOverzichtData[bijlageIndex];
  const rij = document.querySelector(`[data-regel-id="${bijlageKiezerDoelRegelId}"]`);
  if (!rij || !b) return;
  const inputEl = rij.querySelector('.ri-regel-bijlage');
  // Reuse hetzelfde veld als bij uploaden — de rest van de opslaglogica
  // (verzamelRerouteRegels) hoeft dan niet te weten of dit een nieuwe
  // upload was of een selectie uit bestaande bijlagen.
  inputEl.dataset.geuploadJson = JSON.stringify({ bestandsnaam: b.bestandsnaam, pad: b.pad, driveFileId: b.driveFileId, kdrivePad: b.kdrivePad, mimeType: b.mimeType });
  const knop = inputEl.nextElementSibling;
  if (knop) knop.textContent = '📎 ✓';
  sluitModal('modal-bijlage-kiezen');
}

async function bevestigSamenvoegen(bronIndex) {
  const bron = bijlagenOverzichtData[bronIndex];
  const doel = bijlagenOverzichtData[bijlageKiezerSamenvoegDoelIndex];
  if (!bron || !doel) return;
  if (!confirm(`"${doel.bestandsnaam}" wordt samengevoegd met "${bron.bestandsnaam}" — daarna is het nog maar 1 document (met alle koppelingen van beide samen), en verdwijnt het dubbele bestand automatisch. Doorgaan?`)) return;

  const res = await fetch('api_financien.php?action=bijlagen_samenvoegen', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ bronLinks: bron.links, doelLinks: doel.links }),
  });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Samenvoegen mislukt.'); return; }
  sluitModal('modal-bijlage-kiezen');
  await laadBijlagenOverzicht();
}

async function bijlageVerwijderen(i) {
  const b = bijlagenOverzichtData[i];
  if (!b) return;
  const koppelingenTekst = b.links.map(l => l.contextLabel).join(', ');
  if (!confirm(`"${b.bestandsnaam}" volledig verwijderen? Dit haalt álle koppelingen weg (${koppelingenTekst}) en verwijdert het bestand zelf.`)) return;
  const res = await fetch('api_financien.php?action=bijlage_verwijderen', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ links: b.links }),
  });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Verwijderen mislukt.'); return; }
  await laadBijlagenOverzicht();
}

function bijwerkenRerouteTotalen() {
  const som = (containerId) => [...document.querySelectorAll('#' + containerId + ' .ri-regel-bedrag')]
    .reduce((t, el) => t + (parseFloat(el.value) || 0), 0);
  const totaalBijdragen = som('ri-bijdragen-lijst');
  const totaalAankopen = som('ri-aankopen-lijst');
  const totaalKosten = som('ri-bijkomendeKosten-lijst');
  document.getElementById('ri-totaal-bijdragen').textContent = euro(totaalBijdragen);
  document.getElementById('ri-totaal-aankopen').textContent = euro(totaalAankopen);
  document.getElementById('ri-totaal-bijkomendeKosten').textContent = euro(totaalKosten);
  const verschil = Math.round((totaalBijdragen - totaalAankopen - totaalKosten) * 100) / 100;
  const verschilEl = document.getElementById('ri-verschil');
  if (verschil === 0) { verschilEl.textContent = '✓ Bijdragen en uitgaven (aankopen + kosten) zijn sluitend.'; verschilEl.style.color = 'var(--status-groen)'; }
  else { verschilEl.textContent = (verschil > 0 ? '⚠ € ' + verschil.toFixed(2).replace('.', ',') + ' meer ingelegd dan uitgegeven.' : '⚠ € ' + Math.abs(verschil).toFixed(2).replace('.', ',') + ' meer uitgegeven dan ingelegd.'); verschilEl.style.color = 'var(--status-oranje)'; }
}
document.addEventListener('input', (e) => { if (e.target.classList?.contains('ri-regel-bedrag')) bijwerkenRerouteTotalen(); });

function verzamelRerouteRegels(containerId) {
  return [...document.querySelectorAll('#' + containerId + ' [data-regel-id]')].map(rij => {
    const bijlageInput = rij.querySelector('.ri-regel-bijlage');
    const bijlagen = bijlageInput?.dataset.geuploadJson ? [JSON.parse(bijlageInput.dataset.geuploadJson)] : [];
    const gefactureerdVeld = rij.querySelector('.ri-regel-gefactureerd');
    const voorschotVeld = rij.querySelector('.ri-regel-voorschot-user');
    const bestaandeVoorschotBadge = rij.querySelector('[data-bestaande-aanvraag-id]');
    const eigenInlegVeld = rij.querySelector('.ri-regel-eigeninleg');
    const bedragVeld = rij.querySelector('.ri-regel-bedrag');
    const regel = { naam: rij.querySelector('.ri-regel-naam').value, bedrag: parseFloat(bedragVeld.value) || 0, bijlagen };
    if (gefactureerdVeld) regel.gefactureerdDoor = gefactureerdVeld.value;
    if (bestaandeVoorschotBadge) {
      // Al eerder gekoppeld aan een declaratie — die blijft gewoon staan,
      // niet opnieuw aanmaken bij het opnieuw opslaan van deze GI.
      regel.bestaandeGekoppeldeAanvraagId = bestaandeVoorschotBadge.dataset.bestaandeAanvraagId;
    } else if (voorschotVeld && voorschotVeld.value) {
      regel.voorschotAanvragerId = voorschotVeld.value;
    }
    if (eigenInlegVeld) regel.eigenInleg = eigenInlegVeld.checked;
    return regel;
  }).filter(r => r.naam.trim() && r.bedrag > 0);
}

async function rerouteOpslaan() {
  const isoDatum = datumNaarISO(document.getElementById('ri-datum').value);
  if (!isoDatum) { alert('Vul de datum in als dd-mm-jjjj.'); return; }
  const body = {
    titel: document.getElementById('ri-titel').value,
    datum: isoDatum,
    bijdragen: verzamelRerouteRegels('ri-bijdragen-lijst'),
    aankopen: verzamelRerouteRegels('ri-aankopen-lijst'),
    bijkomendeKosten: verzamelRerouteRegels('ri-bijkomendeKosten-lijst'),
  };
  if (!body.titel.trim()) { alert('Titel is verplicht.'); return; }
  if (!body.bijdragen.length || !body.aankopen.length) { alert('Voeg minstens 1 bijdrage én 1 aankoop toe.'); return; }
  const url = huidigeRerouteId
    ? 'api_financien.php?action=reroute_update&id=' + encodeURIComponent(huidigeRerouteId)
    : 'api_financien.php?action=reroute_create';
  const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Opslaan mislukt.'); return; }
  sluitModal('modal-reroute');
  laadReroutes();
  laadTransacties();
  laadBalans();
  alert((huidigeRerouteId ? 'Bijgewerkt: ' : 'Aangemaakt: ') + data.reroute.nummer);
}

async function verwijderReroute(id) {
  if (!confirm('Deze gezamenlijke inkoop verwijderen? Alle gekoppelde boekingen (bijdragen, aankopen én bijkomende kosten) verdwijnen dan ook uit de boekhouding.')) return;
  await fetch('api_financien.php?action=reroute_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadReroutes();
  laadTransacties();
  laadBalans();
}

// ---- Visuele "follow the money"-grafiek ----------------------------------------------------

function toonRerouteGraph(id) {
  const r = reroutesData.find(x => x.id === id);
  if (!r) return;
  const linksItems = r.bijdragen.map(b => ({
    naam: b.naam, bedrag: b.bedrag,
    kleur: b.eigenInleg ? '#3b6fa0' : 'var(--status-groen)',
    achtergrond: b.eigenInleg ? '#e3ecf5' : '#e6f4ea',
    subtekst: b.eigenInleg ? 'eigen inleg' : (b.bijlagen?.length ? '📎' : ''),
    gestippeld: !!b.eigenInleg,
    bijlage: b.bijlagen?.[0] || null,
  }));
  const rechtsItems = [
    ...r.aankopen.map(a => ({ naam: a.naam, bedrag: a.bedrag, kleur: 'var(--status-rood)', achtergrond: '#f9e3df', subtekst: a.bijlagen?.length ? '📎' : '', bijlage: a.bijlagen?.[0] || null })),
    ...(r.bijkomendeKosten || []).map(k => ({
      naam: k.naam, bedrag: k.bedrag,
      kleur: k.gekoppeldeAanvraagId ? '#a3760f' : 'var(--status-oranje)',
      achtergrond: k.gekoppeldeAanvraagId ? '#fdf3df' : '#fbe9dc',
      subtekst: k.gekoppeldeAanvraagId ? '📋 ' + (k.aanvraagNummer || 'declaratie') : (k.gefactureerdDoor ? afkappen(k.gefactureerdDoor, 16) : (k.bijlagen?.length ? '📎' : '')),
      bijlage: k.bijlagen?.[0] || null,
    })),
  ];

  let details = `<div class="card" style="background:var(--primary-light-95); border:none;">`;
  details += `<p style="margin:0 0 8px;">${r.datum ? '<strong>Datum:</strong> ' + formatDatum(r.datum) : ''}</p>`;
  details += `<p style="margin:0 0 4px;"><strong>Bijdragen:</strong></p><ul style="margin:0 0 8px; padding-left:18px; font-size:13px;">`;
  r.bijdragen.forEach(b => { details += `<li>${escapeHtml(b.naam)} — ${euro(b.bedrag)}${b.eigenInleg ? ' <span class="badge grijs">eigen inleg</span>' : ''} ${bijlagenLinks(b.bijlagen)}</li>`; });
  details += `</ul><p style="margin:0 0 4px;"><strong>Aankopen:</strong></p><ul style="margin:0 0 8px; padding-left:18px; font-size:13px;">`;
  r.aankopen.forEach(a => { details += `<li>${escapeHtml(a.naam)} — ${euro(a.bedrag)} ${bijlagenLinks(a.bijlagen)}</li>`; });
  details += `</ul>`;
  if (r.bijkomendeKosten?.length) {
    details += `<p style="margin:0 0 4px;"><strong>Bijkomende kosten:</strong></p><ul style="margin:0 0 8px; padding-left:18px; font-size:13px;">`;
    r.bijkomendeKosten.forEach(k => { details += `<li>${escapeHtml(k.naam)} — ${euro(k.bedrag)}${k.gefactureerdDoor ? ' — gefactureerd door ' + escapeHtml(k.gefactureerdDoor) : ''}${k.gekoppeldeAanvraagId ? ' <span class="badge geel">via declaratie ' + escapeHtml(k.aanvraagNummer || '') + '</span>' : ''} ${bijlagenLinks(k.bijlagen)}</li>`; });
    details += `</ul>`;
  }
  details += `<p style="margin:8px 0 0; font-size:12px; color:var(--grey);">Aangemaakt door ${escapeHtml(r.createdBy)}</p>`;
  details += `</div>`;

  toonFlowGraph(r.nummer + ' — ' + r.titel, r.nummer, r.totaalBijdragen, linksItems, rechtsItems, details);
}

function toonPostGraph(id) {
  const p = postenData.find(x => x.id === id);
  if (!p) return;
  const openstaand = p.totaalbedrag - p.afgehandeldBedrag;
  const linksItems = [{
    naam: p.relatieNaam, bedrag: p.totaalbedrag,
    kleur: 'var(--status-groen)', achtergrond: '#e6f4ea',
    subtekst: openstaand > 0.005 ? euro(openstaand) + ' nog open' : 'volledig betaald',
    bijlage: p.bijlagen?.[0] || null,
  }];
  const rechtsItems = p.regels.map(r => ({
    naam: r.omschrijving, bedrag: r.bedrag,
    kleur: r.voorraadKoppeling ? 'var(--status-rood)' : (r.prive ? 'var(--status-grijs)' : 'var(--status-oranje)'),
    achtergrond: r.voorraadKoppeling ? '#f9e3df' : (r.prive ? '#eef0ef' : '#fbe9dc'),
    subtekst: r.voorraadKoppeling ? (r.voorraadKoppeling.aantal + 'x') : (r.prive ? 'privé, buiten boekhouding' : ''),
    bijlage: r.bijlagen?.[0] || null,
  }));

  let details = `<div class="card" style="background:var(--primary-light-95); border:none;">`;
  details += `<p style="margin:0 0 8px;"><strong>Status:</strong> <span class="badge ${p.status === 'afgehandeld' ? 'groen' : 'geel'}">${escapeHtml(p.status.replace('_', ' '))}</span> — ${euro(p.afgehandeldBedrag)} van ${euro(p.totaalbedrag)} afgehandeld${p.datum ? ' — datum: ' + formatDatum(p.datum) : ''}</p>`;
  details += `<p style="margin:0 0 8px;"><strong>Alle regels:</strong></p><ul style="margin:0 0 8px; padding-left:18px; font-size:13px;">`;
  p.regels.forEach(r => {
    details += `<li>${escapeHtml(r.omschrijving)} — ${euro(r.bedrag)}${r.prive ? ' <span class="badge grijs">privé, niet geboekt</span>' : ''} ${bijlagenLinks(r.bijlagen)}</li>`;
  });
  details += `</ul>`;
  if (p.afhandelingen?.length) {
    details += `<p style="margin:0 0 4px;"><strong>Afhandelingen:</strong></p><ul style="margin:0 0 8px; padding-left:18px; font-size:13px;">`;
    p.afhandelingen.forEach(a => { details += `<li>${formatDatum(a.datum)} — ${euro(a.bedrag)} — ${escapeHtml(a.omschrijving)} (door ${escapeHtml(a.door)})</li>`; });
    details += `</ul>`;
  }
  if (p.factuurnummer) details += `<p style="margin:0; font-size:13px;"><strong>Factuurnummer:</strong> ${escapeHtml(p.factuurnummer)}</p>`;
  if (p.bijlagen?.length) details += `<p style="margin:4px 0 0; font-size:13px;"><strong>Bijlagen post:</strong> ${bijlagenLinks(p.bijlagen)}</p>`;
  details += `<p style="margin:8px 0 0; font-size:12px; color:var(--grey);">Aangemaakt door ${escapeHtml(p.createdBy)}</p>`;
  details += `</div>`;

  toonFlowGraph(p.nummer + ' — ' + p.relatieNaam, p.nummer, p.totaalbedrag, linksItems, rechtsItems, details);
}

function toonAanvraagGraph(id) {
  const a = aanvragenData.find(x => x.id === id);
  if (!a) return;
  const linksItems = [{ naam: a.aanvragerNaam, bedrag: a.bedrag, kleur: 'var(--status-groen)', achtergrond: '#e6f4ea', subtekst: a.bijlagen?.length ? '📎 betaalbewijs' : '', bijlage: a.bijlagen?.[0] || null }];
  const statusInfo = {
    open: { label: 'In behandeling', kleur: 'var(--status-geel)', achtergrond: '#fbf1da' },
    goedgekeurd: { label: 'Goedgekeurd', kleur: 'var(--status-geel)', achtergrond: '#fbf1da' },
    afgewezen: { label: 'Afgewezen', kleur: 'var(--status-rood)', achtergrond: '#f9e3df' },
    uitbetaald: { label: 'Uitbetaald', kleur: 'var(--status-rood)', achtergrond: '#f9e3df' },
  }[a.status];
  const rechtsItems = [{ naam: statusInfo.label, bedrag: a.bedrag, kleur: statusInfo.kleur, achtergrond: statusInfo.achtergrond, subtekst: a.type === 'declaratie' ? 'declaratie' : 'bestelling' }];

  let details = `<div class="card" style="background:var(--primary-light-95); border:none;">`;
  details += `<p style="margin:0 0 8px;"><strong>Status:</strong> <span class="badge ${a.status === 'uitbetaald' || a.status === 'goedgekeurd' ? 'groen' : (a.status === 'afgewezen' ? 'rood' : 'geel')}">${escapeHtml(a.status)}</span>${a.datum ? ' — datum: ' + formatDatum(a.datum) : ''}</p>`;
  details += `<p style="margin:0 0 8px; font-size:13px;"><strong>Omschrijving:</strong> ${escapeHtml(a.omschrijving)}</p>`;
  if (a.leverancier) details += `<p style="margin:0 0 8px; font-size:13px;"><strong>Leverancier:</strong> ${escapeHtml(a.leverancier)}</p>`;
  if (a.bijlagen?.length) details += `<p style="margin:0 0 8px; font-size:13px;"><strong>Bijlagen:</strong> ${bijlagenLinks(a.bijlagen)}</p>`;
  if (a.opmerkingPenningmeester) details += `<p style="margin:0 0 8px; font-size:13px;"><strong>Opmerking penningmeester:</strong> ${escapeHtml(a.opmerkingPenningmeester)}</p>`;
  if (a.betaalgegevens) details += `<p style="margin:0 0 8px; font-size:13px;"><strong>Betaalgegevens:</strong> ${escapeHtml(a.betaalgegevens.iban)} t.n.v. ${escapeHtml(a.betaalgegevens.tenaamstelling)}</p>`;
  details += `<p style="margin:8px 0 0; font-size:12px; color:var(--grey);">Ingediend door ${escapeHtml(a.aanvragerNaam)}${a.behandeldDoor ? ' — behandeld door ' + escapeHtml(a.behandeldDoor) : ''}</p>`;
  details += `</div>`;

  toonFlowGraph((a.nummer || '') + ' — ' + a.aanvragerNaam, a.nummer || '', a.bedrag, linksItems, rechtsItems, details);
}

function toonFlowGraph(titel, poolNummer, poolTotaal, linksItems, rechtsItems, detailsHtml) {
  document.getElementById('rg-titel').textContent = titel;
  document.getElementById('rg-svg-container').innerHTML = flowSvg(poolNummer, poolTotaal, linksItems, rechtsItems);
  document.getElementById('rg-details').innerHTML = detailsHtml || '';
  document.getElementById('modal-flow-graph').style.display = 'flex';
}

// Generieke "follow the money"-grafiek: links = waar het geld vandaan komt,
// midden = de pool/het totaal, rechts = waar het naartoe gaat. Gebruikt door
// Gezamenlijke inkoop, Doorverkopen, en Declaraties/aanvragen.
function flowSvg(poolNummer, poolTotaal, linksItems, rechtsItems) {
  window._flowBijlagen = []; // gereset per render, gebruikt door de hover/klik-handlers hieronder
  function bijlageAttrs(item) {
    if (!item.bijlage) return '';
    window._flowBijlagen.push(item.bijlage);
    const idx = window._flowBijlagen.length - 1;
    return ` style="cursor:pointer;" onmouseenter="toonFinVoorbeeld(event, ${idx})" onmouseleave="verbergFinVoorbeeld()" onmousemove="verplaatsFinVoorbeeld(event)" onclick="openFinBijlage(${idx})"`;
  }

  const rijHoogte = 60;
  const hoogte = Math.max(220, (Math.max(linksItems.length, rechtsItems.length) * rijHoogte) + 60);
  const breedte = 820;
  const linksX = 130, midX = breedte / 2, rechtsX = breedte - 130;
  const linksStartY = (hoogte - linksItems.length * rijHoogte) / 2 + rijHoogte / 2;
  const rechtsStartY = (hoogte - rechtsItems.length * rijHoogte) / 2 + rijHoogte / 2;
  const midY = hoogte / 2;

  let svg = `<svg viewBox="0 0 ${breedte} ${hoogte}" style="width:100%; min-width:640px; font-family:inherit;">`;

  linksItems.forEach((item, i) => {
    const y = linksStartY + i * rijHoogte;
    const stipDash = item.gestippeld ? ' stroke-dasharray="4,3"' : '';
    svg += `<path d="M ${linksX + 55} ${y} C ${midX - 100} ${y}, ${midX - 100} ${midY}, ${midX - 45} ${midY}" fill="none" stroke="${item.kleur}" stroke-width="2" opacity="0.55"${stipDash} />`;
  });
  rechtsItems.forEach((item, i) => {
    const y = rechtsStartY + i * rijHoogte;
    svg += `<path d="M ${midX + 45} ${midY} C ${midX + 100} ${midY}, ${midX + 100} ${y}, ${rechtsX - 55} ${y}" fill="none" stroke="${item.kleur}" stroke-width="2" opacity="0.55" />`;
  });

  svg += `<circle cx="${midX}" cy="${midY}" r="46" fill="var(--primary-light-95)" stroke="var(--primary)" stroke-width="2" />`;
  svg += `<text x="${midX}" y="${midY - 6}" text-anchor="middle" font-size="11" fill="var(--grey)">${escapeHtml(poolNummer)}</text>`;
  svg += `<text x="${midX}" y="${midY + 12}" text-anchor="middle" font-size="13" font-weight="700" fill="var(--primary)">${euro(poolTotaal)}</text>`;

  linksItems.forEach((item, i) => {
    const y = linksStartY + i * rijHoogte;
    svg += `<g${bijlageAttrs(item)}>`;
    svg += `<circle cx="${linksX}" cy="${y}" r="42" fill="${item.achtergrond}" stroke="${item.kleur}" stroke-width="${item.bijlage ? 2.5 : 1.5}" />`;
    svg += `<text x="${linksX}" y="${y - 4}" text-anchor="middle" font-size="11" fill="var(--ink)">${escapeHtml(afkappen(item.naam, 14))}</text>`;
    svg += `<text x="${linksX}" y="${y + 12}" text-anchor="middle" font-size="12" font-weight="700" fill="${item.kleur}">${euro(item.bedrag)}</text>`;
    if (item.subtekst) svg += `<text x="${linksX}" y="${y + 26}" text-anchor="middle" font-size="9.5" fill="var(--grey)">${escapeHtml(item.subtekst)}</text>`;
    svg += `</g>`;
  });

  rechtsItems.forEach((item, i) => {
    const y = rechtsStartY + i * rijHoogte;
    svg += `<g${bijlageAttrs(item)}>`;
    svg += `<circle cx="${rechtsX}" cy="${y}" r="42" fill="${item.achtergrond}" stroke="${item.kleur}" stroke-width="${item.bijlage ? 2.5 : 1.5}" />`;
    svg += `<text x="${rechtsX}" y="${y - 4}" text-anchor="middle" font-size="11" fill="var(--ink)">${escapeHtml(afkappen(item.naam, 14))}</text>`;
    svg += `<text x="${rechtsX}" y="${y + 12}" text-anchor="middle" font-size="12" font-weight="700" fill="${item.kleur}">${euro(item.bedrag)}</text>`;
    if (item.subtekst) svg += `<text x="${rechtsX}" y="${y + 26}" text-anchor="middle" font-size="9.5" fill="var(--grey)">${escapeHtml(item.subtekst)}</text>`;
    svg += `</g>`;
  });

  svg += '</svg>';
  return svg;
}

function toonFinVoorbeeld(evt, bijlageIdx) {
  const b = window._flowBijlagen[bijlageIdx];
  if (!b) return;
  const overlay = document.getElementById('fin-voorbeeld-overlay');
  const img = document.getElementById('fin-voorbeeld-img');
  const pdf = document.getElementById('fin-voorbeeld-pdf');
  const url = bijlageDownloadUrl(b);
  const isAfbeelding = /\.(jpe?g|png)$/i.test(b.bestandsnaam || '') || (b.mimeType || '').startsWith('image/');
  img.style.display = 'none'; pdf.style.display = 'none'; pdf.src = '';
  if (isAfbeelding) { img.src = url; img.style.display = 'block'; }
  else { pdf.src = url + '#toolbar=0&navpanes=0&scrollbar=0&view=FitH'; pdf.style.display = 'block'; }
  overlay.style.display = 'block';
  verplaatsFinVoorbeeld(evt);
}

function verplaatsFinVoorbeeld(evt) {
  const overlay = document.getElementById('fin-voorbeeld-overlay');
  const marge = 16;
  let x = evt.clientX + marge, y = evt.clientY + marge;
  if (x + 440 > window.innerWidth) x = evt.clientX - 440 - marge;
  if (y + 440 > window.innerHeight) y = evt.clientY - 440 - marge;
  overlay.style.left = Math.max(8, x) + 'px';
  overlay.style.top = Math.max(8, y) + 'px';
}

function verbergFinVoorbeeld() {
  document.getElementById('fin-voorbeeld-overlay').style.display = 'none';
  document.getElementById('fin-voorbeeld-pdf').src = '';
}

function openFinBijlage(bijlageIdx) {
  const b = window._flowBijlagen[bijlageIdx];
  if (!b) return;
  window.open(bijlageDownloadUrl(b), '_blank');
}

function afkappen(s, n) { return (s || '').length > n ? s.slice(0, n - 1) + '…' : (s || ''); }

laadInstellingenFinancien().then(() => {
  laadBalans();
  laadReroutes();
  laadTransacties();
  laadAanvragen();
  laadRelaties();
  laadPosten();
  laadDerving();
  laadOpslagStatus();
});

const params = new URLSearchParams(location.search);
if (params.get('tab')) toonView(params.get('tab'));
</script>
</body>
</html>
