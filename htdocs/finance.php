<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$isFinancien = heeft_rol(['admin', 'treasurer']);
$magVerifieren = heeft_rol(['admin', 'treasurer']);
$magDerving = heeft_rol(['admin', 'treasurer', 'secretary', 'coordinator_distribution']);
$isVoorzitter = heeft_rol(['admin', 'chair']);
$eigenUserId = $_SESSION['user_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Finance — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
<link rel="stylesheet" href="style.css.php" />
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://unpkg.com/papaparse@5.4.1/papaparse.min.js"></script>
<style>
  /* Date and amount columns shouldn't wrap to a second line — that looks
     messy and makes the table needlessly tall. */
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
      <button class="secondary" onclick="toonView('balans')">Overview</button>
      <button class="secondary" onclick="toonView('boekhouding')">Bookkeeping</button>
      <button class="secondary" onclick="toonView('aanvragen')">Reimbursements &amp; requests</button>
      <button class="secondary" onclick="toonView('posten')">Resales</button>
      <button class="secondary" onclick="toonView('reroutes')">Joint purchases</button>
    </div>
    <div class="right">
      <button class="primary" onclick="openAanvraagForm('declaratie')">+ Submit reimbursement</button>
      <?php if ($isVoorzitter): ?><button class="primary" onclick="openAanvraagForm('bestelling_aanvraag')">+ Order request</button><?php endif; ?>
    </div>
  </div>

  <!-- ===== Overview ===== -->
  <div id="view-balans" class="card">
    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:20px;">
      <div class="card" style="text-align:center; position:relative;">
        <div style="color:var(--grey); font-size:12px;">Starting balance</div>
        <div id="bal-startsaldo" style="font-size:22px; font-weight:700; color:var(--ink);">—</div>
        <?php if ($isFinancien): ?><button class="secondary" onclick="startsaldoBewerken()" style="position:absolute; top:6px; right:6px; padding:2px 8px; font-size:11px;">✎</button><?php endif; ?>
      </div>
      <div class="card" style="text-align:center;"><div style="color:var(--grey); font-size:12px;">Income</div><div id="bal-inkomsten" style="font-size:22px; font-weight:700; color:var(--status-groen);">—</div></div>
      <div class="card" style="text-align:center;"><div style="color:var(--grey); font-size:12px;">Expenses</div><div id="bal-uitgaven" style="font-size:22px; font-weight:700; color:var(--status-rood);">—</div></div>
      <div class="card" style="text-align:center;"><div style="color:var(--grey); font-size:12px;">Balance</div><div id="bal-saldo" style="font-size:22px; font-weight:700; color:var(--primary);">—</div></div>
    </div>
    <strong>Expenses by category</strong>
    <div id="bal-categorieen" style="margin-top:8px;"></div>
  </div>

  <!-- ===== Bookkeeping ===== -->
  <div id="view-boekhouding" class="card" style="display:none;">
    <div class="toolbar">
      <strong>Bookkeeping — income &amp; expenses</strong>
      <div class="right">
        <a class="btn-primary" href="api_finance.php?action=export_transacties_csv">⬇ Export CSV</a>
        <?php if ($isFinancien): ?>
        <a class="btn-primary" href="templates/financien_transacties_sjabloon.csv" download>⬇ CSV template</a>
        <button class="secondary" onclick="document.getElementById('transactieCsvInput').click()">⬆ Bulk import</button>
        <input type="file" id="transactieCsvInput" accept=".csv" style="display:none;" />
        <button class="primary" onclick="openTransactieForm()">+ Add transaction</button>
        <?php endif; ?>
      </div>
    </div>
    <table>
      <thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Category</th><th>Amount</th><th>Attachment</th><th>Verified</th><th></th></tr></thead>
      <tbody id="transacties-body"><tr><td colspan="7">Loading…</td></tr></tbody>
    </table>

    <div class="toolbar" style="margin-top:24px;">
      <strong>Shrinkage — mis-purchases, lost shipments &amp; damage</strong>
      <div class="right">
        <a class="btn-primary" href="api_finance.php?action=export_derving_csv">⬇ Export CSV</a>
        <?php if ($magDerving): ?><button class="primary" onclick="openDervingForm()">+ Record shrinkage</button><?php endif; ?>
      </div>
    </div>
    <table>
      <thead><tr><th>Date</th><th>Item</th><th>Quantity</th><th>Reason</th><th>Loss</th><th>Note</th><th></th></tr></thead>
      <tbody id="derving-body"><tr><td colspan="7">Loading…</td></tr></tbody>
    </table>

    <?php if ($isFinancien): ?>
    <div class="toolbar" style="margin-top:24px;">
      <strong>Storage management</strong>
    </div>
    <div id="opslag-status" style="font-size:13px; color:var(--grey); margin-bottom:8px;">Loading…</div>
    <div style="background:var(--border); border-radius:6px; height:8px; overflow:hidden; margin-bottom:12px;">
      <div id="opslag-balk" style="background:var(--primary); height:100%; width:0%;"></div>
    </div>
    <div style="display:flex; gap:8px;">
      <button class="secondary" onclick="opslagBackupDownloaden()">⬇ Download a backup of all attachments</button>
      <button class="secondary" style="color:#c9432f; border-color:#c9432f;" onclick="opslagWissenNaBackup()">🗑 Wipe local attachments (after backing up!)</button>
    </div>
    <p style="font-size:11.5px; color:var(--grey); margin-top:6px;">Wiping only removes the files themselves from the server — the records (amounts, descriptions, filenames) stay exactly as they are, with a "backup only" label.</p>
    <?php endif; ?>
  </div>

  <!-- ===== Reimbursements & requests ===== -->
  <div id="view-aanvragen" class="card" style="display:none;">
    <div class="toolbar">
      <strong>Reimbursements &amp; order requests</strong>
      <div class="right">
        <a class="btn-primary" href="api_finance.php?action=export_aanvragen_csv">⬇ Export CSV</a>
        <?php if ($isFinancien): ?>
        <a class="btn-primary" href="templates/financien_declaraties_sjabloon.csv" download>⬇ CSV template (import old reimbursements)</a>
        <button class="secondary" onclick="document.getElementById('declaratieCsvInput').click()">⬆ Bulk import</button>
        <input type="file" id="declaratieCsvInput" accept=".csv" style="display:none;" />
        <?php endif; ?>
      </div>
    </div>
    <table>
      <thead><tr><th>Number</th><th>Type</th><th>Requester</th><th>Description</th><th>Amount</th><th>Status</th><th></th></tr></thead>
      <tbody id="aanvragen-body"><tr><td colspan="7">Loading…</td></tr></tbody>
    </table>
  </div>

  <!-- ===== Debtors & creditors ===== -->
  <div id="view-posten" class="card" style="display:none;">
    <div class="toolbar">
      <strong>Resales</strong>
      <div class="right">
        <a class="btn-primary" href="api_finance.php?action=export_posten_csv">⬇ Export CSV</a>
        <?php if ($isFinancien): ?>
        <button class="secondary" onclick="openRelatieForm()">+ Add contact</button>
        <?php endif; ?>
        <button class="primary" onclick="openPostForm()">+ Resale</button>
      </div>
    </div>

    <h3 style="color:var(--primary); margin:6px 0 8px;">Outstanding &amp; settled resales</h3>
    <table>
      <thead><tr><th>Number</th><th>Kind</th><th>Contact</th><th>Lines</th><th>Total</th><th>Outstanding</th><th>Status</th><th></th></tr></thead>
      <tbody id="posten-body"><tr><td colspan="8">Loading…</td></tr></tbody>
    </table>

    <h3 style="color:var(--primary); margin:22px 0 8px;">Contacts</h3>
    <table>
      <thead><tr><th>Name</th><th>Type</th><th>Contact person</th><th>Email</th><th></th></tr></thead>
      <tbody id="relaties-body"><tr><td colspan="5">Loading…</td></tr></tbody>
    </table>
  </div>

  <!-- ===== Joint purchases ===== -->
  <div id="view-reroutes" class="card" style="display:none;">
    <div class="toolbar">
      <strong>Joint purchases — follow the money</strong>
      <?php if ($isFinancien): ?><button class="primary" onclick="openRerouteForm()">+ New joint purchase</button><?php endif; ?>
    </div>
    <p style="color:var(--grey); font-size:12.5px;">Multiple districts chip in together (contributions) for a joint order with one or more suppliers (purchases). Each line automatically becomes its own booking in the main ledger, all linked via the same number.</p>
    <table>
      <thead><tr><th>Number</th><th>Title</th><th>Contributions</th><th>Purchases + costs</th><th>Difference</th><th></th></tr></thead>
      <tbody id="reroutes-body"><tr><td colspan="6">Loading…</td></tr></tbody>
    </table>
  </div>

</div>

<!-- ===== Modal: create joint purchase ===== -->
<div id="modal-reroute" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-reroute')">
  <div class="modal" style="max-width:680px;">
    <button class="modal-close" onclick="sluitModal('modal-reroute')">✕</button>
    <h2 id="ri-titel-kop">New joint purchase</h2>
    <div class="form-grid">
      <div><label>Title</label><input id="ri-titel" placeholder="e.g. Joint print order March 2026" /></div>
      <div><label>Date</label><input id="ri-datum" type="date" /></div>
    </div>

    <h3 style="color:var(--primary); margin:16px 0 6px;">Contributions (who's chipping in what)</h3>
    <div id="ri-bijdragen-lijst"></div>
    <button type="button" class="secondary" onclick="voegRerouteRegelToe('bijdragen')">+ Add contribution</button>
    <p style="margin:8px 0; font-size:13px;">Total contributions: <strong id="ri-totaal-bijdragen">0.00</strong></p>

    <h3 style="color:var(--primary); margin:16px 0 6px;">Purchases (where the money is going)</h3>
    <div id="ri-aankopen-lijst"></div>
    <button type="button" class="secondary" onclick="voegRerouteRegelToe('aankopen')">+ Add purchase</button>
    <p style="margin:8px 0; font-size:13px;">Total purchases: <strong id="ri-totaal-aankopen">0.00</strong></p>

    <h3 style="color:var(--primary); margin:16px 0 6px;">Additional costs (e.g. shipping to get items to the right recipient)</h3>
    <div id="ri-bijkomendeKosten-lijst"></div>
    <button type="button" class="secondary" onclick="voegRerouteRegelToe('bijkomendeKosten')">+ Add cost</button>
    <p style="margin:8px 0; font-size:13px;">Total additional costs: <strong id="ri-totaal-bijkomendeKosten">0.00</strong></p>

    <p id="ri-verschil" style="font-size:13px; font-weight:600;"></p>

    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="rerouteOpslaan()">Save</button>
    </div>
  </div>
</div>

<!-- ===== Modal: joint purchase chart ===== -->
<div id="modal-flow-graph" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-flow-graph')">
  <div class="modal" style="max-width:900px;">
    <button class="modal-close" onclick="sluitModal('modal-flow-graph')">✕</button>
    <h2 id="rg-titel">Joint purchase</h2>
    <div id="rg-svg-container" style="overflow-x:auto;"></div>
    <div id="rg-details" style="margin-top:16px;"></div>
  </div>
</div>

<!-- ===== Modal: add/edit transaction ===== -->
<div id="modal-transactie" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-transactie')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-transactie')">✕</button>
    <h2 id="transactie-titel">Add transaction</h2>
    <div class="form-grid">
      <div><label>Type</label>
        <select id="t-type"><option value="uitgave">Expense</option><option value="inkomst">Income</option></select>
      </div>
      <div><label>Amount</label><input id="t-bedrag" type="number" step="0.01" min="0" /></div>
      <div><label>Date</label><input id="t-datum" type="date" /></div>
      <div><label>Category</label>
        <select id="t-categorie-select" onchange="categorieDropdownGewijzigd()"></select>
        <input id="t-categorie-overig" type="text" placeholder="Custom category" style="display:none; margin-top:6px;" />
      </div>
    </div>
    <label>Description</label><textarea id="t-omschrijving" rows="2"></textarea>
    <label>Attachments (invoice/receipt — PDF, JPG, or PNG)</label>
    <input type="file" id="t-bijlage" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" />
    <button type="button" class="secondary" onclick="document.getElementById('t-bijlage').click()">+ Add attachment</button>
    <div id="t-bijlage-status" style="font-size:12px; color:var(--grey); margin-top:4px;"></div>
    <div id="t-bijlagen-lijst" style="margin-top:6px;"></div>
    <label>Reference (optional — link to a joint purchase, resale, or reimbursement/request)</label>
    <div class="form-grid">
      <div>
        <select id="t-referentie-type" onchange="vulReferentieOpties()">
          <option value="">— no reference —</option>
          <option value="reroute">Joint purchase (GI-...)</option>
          <option value="post">Resale (DV-...)</option>
          <option value="aanvraag">Reimbursement/request (DC-.../BA-...)</option>
        </select>
      </div>
      <div><select id="t-referentie-id"><option value="">— choose a type first —</option></select></div>
    </div>
    <div style="margin-top:14px; display:flex; justify-content:flex-end; gap:8px;">
      <button class="secondary" onclick="sluitModal('modal-transactie')">Cancel</button>
      <button class="primary" onclick="opslaanTransactie()">Save</button>
    </div>
  </div>
</div>

<!-- ===== Modal: record shrinkage ===== -->
<div id="modal-derving" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-derving')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-derving')">✕</button>
    <h2>Record shrinkage</h2>
    <label>Item</label>
    <select id="d-item"></select>
    <div class="form-grid">
      <div><label>Quantity</label><input id="d-aantal" type="number" min="1" value="1" /></div>
      <div><label>Reason</label>
        <select id="d-reden">
          <option value="miskoop">Mis-purchase</option>
          <option value="verloren_zending">Lost shipment</option>
          <option value="schade">Damage</option>
          <option value="overig">Other</option>
        </select>
      </div>
    </div>
    <p id="d-verlies-preview" style="font-size:13px; color:var(--grey);"></p>
    <label>Note (optional)</label><textarea id="d-toelichting" rows="2"></textarea>
    <label>Photos/evidence (optional — PDF, JPG, or PNG, multiple allowed)</label>
    <input type="file" id="d-bijlage" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" />
    <button type="button" class="secondary" onclick="document.getElementById('d-bijlage').click()">+ Add attachment</button>
    <div id="d-bijlagen-lijst" style="margin-top:6px;"></div>
    <div id="d-bijlage-status" style="font-size:12px; color:var(--grey); margin-top:4px;"></div>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="dervingOpslaan()">Record</button>
    </div>
  </div>
</div>

<!-- ===== Modal: submit reimbursement / order request ===== -->
<div id="modal-aanvraag" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-aanvraag')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-aanvraag')">✕</button>

    <div id="aanvraag-formulier-velden">
      <h2 id="aanvraag-titel">Submit reimbursement</h2>
      <div class="form-grid">
        <div><label>Amount</label><input id="a-bedrag" type="number" step="0.01" min="0" /></div>
        <div><label>Date</label><input id="a-datum" type="date" /></div>
      </div>
      <label id="a-leverancier-label" style="display:none;">Supplier</label>
      <input id="a-leverancier" style="display:none;" />
      <label>Description</label><textarea id="a-omschrijving" rows="2" placeholder="What was this for?"></textarea>
      <label>Attachments (proof of payment/invoice — PDF, JPG, or PNG, multiple allowed)</label>
      <input type="file" id="a-bijlage" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" />
      <button type="button" class="secondary" onclick="document.getElementById('a-bijlage').click()">+ Add attachment</button>
      <div id="a-bijlagen-lijst" style="margin-top:6px;"></div>
      <div id="a-bijlage-status" style="font-size:12px; color:var(--grey); margin-top:4px;"></div>
      <div style="margin-top:14px; display:flex; justify-content:flex-end; gap:8px;">
        <button class="secondary" onclick="sluitModal('modal-aanvraag')">Cancel</button>
        <button class="primary" onclick="opslaanAanvraag()">Submit</button>
      </div>
    </div>

    <div id="aanvraag-succes" style="display:none; text-align:center; padding:10px 0;">
      <p style="font-size:32px; margin:0;">✓</p>
      <p style="color:var(--primary); font-weight:600;">Submitted! The treasurer has been notified (in-portal + email).</p>
      <a id="aanvraag-whatsapp-link" href="#" target="_blank" rel="noopener" class="btn-primary" style="margin-top:6px;">📱 Also send via WhatsApp</a>
      <br><button class="secondary" style="margin-top:14px;" onclick="sluitAanvraagModalNaSucces()">Close</button>
    </div>
  </div>
</div>

<!-- ===== Modal: review request (finance role) ===== -->
<div id="modal-besluit" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-besluit')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-besluit')">✕</button>
    <h2>Review request</h2>
    <p id="besluit-info" style="color:var(--grey); font-size:13.5px;"></p>
    <label>Note (optional)</label><textarea id="besluit-opmerking" rows="2"></textarea>
    <div style="margin-top:14px; display:flex; justify-content:flex-end; gap:8px;">
      <button class="secondary" onclick="besluitNemen('afgewezen')" style="color:#c9432f; border-color:#c9432f;">Reject</button>
      <button class="primary" onclick="besluitNemen('goedgekeurd')">Approve</button>
    </div>
  </div>
</div>

<!-- ===== Modal: submit payment details (requester) ===== -->
<div id="modal-betaalgegevens" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-betaalgegevens')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-betaalgegevens')">✕</button>
    <h2>Submit payment details</h2>
    <p style="color:var(--grey); font-size:13px;">Your request has been approved — let us know where to transfer the amount.</p>
    <label>IBAN / account number</label><input id="bg-iban" placeholder="e.g. NL00 BANK 0000 0000 00" />
    <label>Account holder name</label><input id="bg-tnv" />
    <label>Note (optional)</label><textarea id="bg-opmerking" rows="2"></textarea>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="betaalgegevensOpslaan()">Send</button>
    </div>
  </div>
</div>

<!-- ===== Modal: add/edit contact ===== -->
<div id="modal-relatie" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-relatie')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-relatie')">✕</button>
    <h2 id="relatie-titel">Add contact</h2>
    <label>Name</label><input id="r-naam" placeholder="e.g. North Region, or supplier name" />
    <label>Type</label>
    <select id="r-type"><option value="beide">Debtor &amp; creditor</option><option value="debiteur">Debtor</option><option value="crediteur">Creditor</option></select>
    <div class="form-grid">
      <div><label>Contact person</label><input id="r-contactpersoon" /></div>
      <div><label>Email</label><input id="r-email" type="email" /></div>
    </div>
    <label>Phone</label><input id="r-telefoon" />
    <label>Note</label><textarea id="r-notitie" rows="2"></textarea>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="relatieOpslaan()">Save</button>
    </div>
  </div>
</div>

<!-- ===== Modal: create resale / on-account purchase ===== -->
<div id="modal-post" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-post')">
  <div class="modal" style="max-width:640px;">
    <button class="modal-close" onclick="sluitModal('modal-post')">✕</button>
    <h2 id="post-titel">Create resale</h2>
    <div class="form-grid">
      <div><label>Contact</label><select id="p-relatie"></select></div>
      <div><label>Date</label><input id="p-datum" type="date" /></div>
    </div>
    <div id="p-factuur-velden" style="display:none;">
      <label>Invoice number (from the supplier)</label><input id="p-factuurnummer" />
      <label>Invoice(s) (PDF/photo, multiple allowed)</label>
      <input type="file" id="p-bijlage" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" />
      <button type="button" class="secondary" onclick="document.getElementById('p-bijlage').click()">+ Add attachment</button>
      <div id="p-bijlagen-lijst" style="margin-top:6px;"></div>
      <div id="p-bijlage-status" style="font-size:12px; color:var(--grey); margin-top:4px;"></div>
    </div>

    <h3 style="color:var(--primary); margin:16px 0 6px;">Lines</h3>
    <div id="p-regels-lijst"></div>

    <div style="display:flex; gap:6px; margin-top:10px; flex-wrap:wrap;">
      <button class="secondary" onclick="voegVoorraadRegelToe()">+ Stock line</button>
      <button class="secondary" onclick="voegVrijeRegelToe()">+ Free-text line (e.g. shipping costs)</button>
    </div>

    <p style="margin-top:10px; font-weight:600;">Total: <span id="p-totaal">0.00</span></p>

    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="postOpslaan()">Create</button>
    </div>
  </div>
</div>

<!-- ===== Modal: settle resale (payment received/made) ===== -->
<div id="modal-post-afhandelen" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-post-afhandelen')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-post-afhandelen')">✕</button>
    <h2 id="afhandelen-titel">Settle entry</h2>
    <p style="color:var(--grey); font-size:13px;">Tick the lines that have now been paid.</p>
    <div id="afhandelen-regels"></div>
    <p style="margin-top:10px; font-weight:600;">To be booked: <span id="afhandelen-totaal">0.00</span></p>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="postAfhandelenOpslaan()">Confirm</button>
    </div>
  </div>
</div>

<script>
const isFinancien = <?= $isFinancien ? 'true' : 'false' ?>;

const magVerifieren = <?= $magVerifieren ? 'true' : 'false' ?>;
const standaardCategorieen = <?= json_encode(TRANSACTION_CATEGORIES, JSON_UNESCAPED_UNICODE) ?>;
const magDerving = <?= $magDerving ? 'true' : 'false' ?>;
const eigenUserId = <?= json_encode($eigenUserId) ?>;
const eigenUserNaam = <?= json_encode($_SESSION['user_name'] ?? '') ?>;
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
  ['balans','boekhouding','aanvragen','posten','reroutes'].forEach(x => document.getElementById('view-' + x).style.display = x === v ? '' : 'none');
}
function sluitModal(id) { document.getElementById(id).style.display = 'none'; }
function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

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
  const res = await fetch('api_members.php?action=instellingen_get', { cache: 'no-store' });
  const data = await res.json();
  datumformaatVolgorde = data.instellingen.datumformaat || 'dmy';
}
function euro(v) { return v === null || v === undefined ? '—' : Number(v).toFixed(2); }
function vandaagAlsDatum() { return new Date().toISOString().slice(0, 10); }

function bijlagenLinks(bijlagen) {
  if (!bijlagen || !bijlagen.length) return '—';
  return bijlagen.map((b, i) => {
    if (b.gearchiveerd) return `📎 ${escapeHtml(b.bestandsnaam || '')} <span class="badge grijs">backup only</span>`;
    let params;
    if (b.kdrivePad) params = `kdrivePad=${encodeURIComponent(b.kdrivePad)}&mime=${encodeURIComponent(b.mimeType || 'application/octet-stream')}`;
    else if (b.driveFileId) params = `driveFileId=${encodeURIComponent(b.driveFileId)}&mime=${encodeURIComponent(b.mimeType || 'application/octet-stream')}`;
    else params = `pad=${encodeURIComponent(b.pad)}`;
    return `<a href="api_finance.php?action=bijlage_download&${params}" target="_blank">📎${bijlagen.length > 1 ? ' ' + (i + 1) : ''}</a>`;
  }).join(' ');
}

// ---- Overview ----------------------------------------------------

async function laadBalans() {
  const res = await fetch('api_finance.php?action=balans', { cache: 'no-store' });
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
    </div>`).join('') || '<p style="color:var(--grey);">No expenses booked yet.</p>';
}

let huidigStartsaldo = 0;
async function startsaldoBewerken() {
  const invoer = prompt('Starting balance — the amount that already existed before this bookkeeping began:', huidigStartsaldo);
  if (invoer === null) return;
  const bedrag = parseFloat(invoer.replace(',', '.'));
  if (isNaN(bedrag)) { alert('Enter a valid amount.'); return; }
  await fetch('api_finance.php?action=startsaldo_bijwerken', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ startsaldo: bedrag }),
  });
  laadBalans();
}

// ---- Bookkeeping ----------------------------------------------------

let transactiesData = [];
let aanvragenData = [];

function renderRerouteBadge(rerouteId) {
  const r = reroutesData.find(x => x.id === rerouteId);
  if (!r) return '';
  return ` <span class="badge grijs" style="cursor:pointer;" onclick="toonRerouteGraph('${r.id}')" title="Part of a joint purchase">${escapeHtml(r.nummer)}</span>`;
}

function renderReferentieBadge(t) {
  if (t.gekoppeldeRerouteId) return renderRerouteBadge(t.gekoppeldeRerouteId);
  if (t.gekoppeldePostId) {
    const p = postenData.find(x => x.id === t.gekoppeldePostId);
    return p ? ` <span class="badge grijs" style="cursor:pointer;" onclick="toonPostGraph('${p.id}')" title="Part of a resale — click for an overview">${escapeHtml(p.nummer)}</span>` : '';
  }
  if (t.gekoppeldeAanvraagId) {
    const a = aanvragenData.find(x => x.id === t.gekoppeldeAanvraagId);
    return a ? ` <span class="badge grijs" style="cursor:pointer;" onclick="toonAanvraagGraph('${a.id}')" title="Part of a reimbursement/order request — click for an overview">${escapeHtml(a.nummer || '')}</span>` : '';
  }
  return '';
}

async function laadTransacties() {
  const res = await fetch('api_finance.php?action=transactie_list');
  const data = await res.json();
  transactiesData = data.transacties;
  document.getElementById('transacties-body').innerHTML = transactiesData.map(t => `
    <tr>
      <td>${formatDatum(t.datum)}</td>
      <td><span class="badge ${t.type === 'inkomst' ? 'groen' : 'rood'}">${t.type === 'inkomst' ? 'income' : 'expense'}</span></td>
      <td>${escapeHtml(t.omschrijving)}</td>
      <td>${escapeHtml(t.categorie || '—')}</td>
      <td>${euro(t.bedrag)}${renderReferentieBadge(t)}</td>
      <td>${bijlagenLinks(t.bijlagen)}</td>
      <td style="text-align:center; white-space:nowrap;">${renderVerificatieVinkje(t)}</td>
      <td style="white-space:nowrap;">
        ${isFinancien ? `<button class="secondary" onclick="openTransactieForm('${t.id}')">Edit</button> <button class="secondary" onclick="verwijderTransactie('${t.id}')">✕</button>` : ''}
      </td>
    </tr>`).join('') || '<tr><td colspan="8">No transactions yet.</td></tr>';
}

function renderVerificatieVinkje(t) {
  const titel = t.geverifieerd ? `Verified by ${t.geverifieerdDoor} on ${formatDatum(t.geverifieerdOp)}` : 'Not yet verified';
  if (magVerifieren) {
    return `<input type="checkbox" ${t.geverifieerd ? 'checked' : ''} onchange="verifieerTransactie('${t.id}', this.checked)" title="${titel}" style="cursor:pointer; width:auto;" />`;
  }
  return t.geverifieerd
    ? `<span class="badge groen" title="${titel}">✓ verified</span>`
    : `<span class="badge grijs" title="${titel}">—</span>`;
}

async function verifieerTransactie(id, geverifieerd) {
  await fetch('api_finance.php?action=transactie_verifieren&id=' + encodeURIComponent(id), {
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
  statusEl.textContent = 'Uploading…';
  const fd = new FormData();
  fd.append('bestand', file);
  const res = await fetch('api_finance.php?action=bijlage_upload', { method: 'POST', credentials: 'same-origin', body: fd });
  const data = await res.json();
  if (!res.ok) { statusEl.textContent = data.error || 'Upload failed.'; return null; }
  statusEl.textContent = '✓ added: ' + data.bestandsnaam;
  return data;
}

document.getElementById('t-bijlage').addEventListener('change', async (e) => {
  if (!e.target.files.length) return;
  const resultaat = await uploadEenBestand(e.target.files[0], document.getElementById('t-bijlage-status'));
  e.target.value = '';
  if (resultaat) { bijlageT.push(resultaat); renderBijlagenBeheer('t-bijlagen-lijst', bijlageT, 'verwijderBijlageT'); }
});
function verwijderBijlageT(i) { bijlageT.splice(i, 1); renderBijlagenBeheer('t-bijlagen-lijst', bijlageT, 'verwijderBijlageT'); }

function categorieDropdownGewijzigd() {
  const isOverig = document.getElementById('t-categorie-select').value === '__overig__';
  document.getElementById('t-categorie-overig').style.display = isOverig ? 'block' : 'none';
}

function vulCategorieDropdown(huidigeWaarde) {
  const select = document.getElementById('t-categorie-select');
  select.innerHTML = '<option value="">— no category —</option>' +
    standaardCategorieen.map(c => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('') +
    '<option value="__overig__">Other…</option>';

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
  if (!type) { select.innerHTML = '<option value="">— choose a type first —</option>'; return; }
  const lijst = lijsten[type] || [];
  select.innerHTML = '<option value="">— choose —</option>' + lijst.map(x => `<option value="${x.id}">${escapeHtml(labelFn[type](x))}</option>`).join('');
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
  document.getElementById('transactie-titel').textContent = t ? 'Edit transaction' : 'Add transaction';
  document.getElementById('t-type').value = t ? t.type : 'uitgave';
  document.getElementById('t-bedrag').value = t ? t.bedrag : '';
  document.getElementById('t-datum').value = t ? t.datum : vandaagAlsDatum();
  vulCategorieDropdown(t ? (t.categorie || '') : '');
  document.getElementById('t-omschrijving').value = t ? t.omschrijving : '';
  document.getElementById('t-bijlage').value = '';
  document.getElementById('t-bijlage-status').textContent = '';
  renderBijlagenBeheer('t-bijlagen-lijst', bijlageT, 'verwijderBijlageT');
  vulReferentieVoorIn(t);
  document.getElementById('modal-transactie').style.display = 'flex';
}

async function opslaanTransactie() {
  const isoDatum = document.getElementById('t-datum').value;
  if (!isoDatum) { alert('Choose a date.'); return; }
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
  if (!body.bedrag || !body.omschrijving.trim()) { alert('Amount and description are required.'); return; }
  const url = huidigeTransactieId
    ? 'api_finance.php?action=transactie_update&id=' + encodeURIComponent(huidigeTransactieId)
    : 'api_finance.php?action=transactie_create';
  const res = await fetch(url, {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  if (!res.ok) { alert('Save failed.'); return; }
  sluitModal('modal-transactie');
  laadTransacties();
  laadBalans();
}

async function verwijderTransactie(id) {
  if (!confirm('Delete this transaction?')) return;
  await fetch('api_finance.php?action=transactie_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
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
        if (!confirm(`Import ${results.data.length} rows?`)) return;
        await fetch('api_finance.php?action=transactie_bulk_import', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ rijen: results.data }) });
        transactieCsvInput.value = '';
        laadTransacties();
        laadBalans();
      },
    });
  });
}

// ---- Reimbursements & requests ----------------------------------------------------

async function laadAanvragen() {
  const res = await fetch('api_finance.php?action=aanvraag_list');
  const data = await res.json();
  aanvragenData = data.aanvragen;
  const statusBadge = { open: 'geel', goedgekeurd: 'groen', afgewezen: 'rood', uitbetaald: 'groen' };
  const statusLabel = { open: 'open', goedgekeurd: 'approved', afgewezen: 'rejected', uitbetaald: 'paid' };

  document.getElementById('aanvragen-body').innerHTML = aanvragenData.map(a => {
    const isEigen = a.aanvragerId === eigenUserId;
    const magBewerken = a.status === 'open' && (isEigen || isFinancien);
    let acties = '';
    if (magBewerken) acties += `<button class="secondary" onclick='openAanvraagForm(${JSON.stringify(a.type)}, ${JSON.stringify(a)})'>Edit</button> `;
    if (isFinancien && a.status === 'open') acties += `<button class="secondary" onclick='openBesluitForm(${JSON.stringify(a)})'>Review</button> `;
    if (isEigen && a.status === 'goedgekeurd' && !a.betaalgegevens) acties += `<button class="secondary" onclick='openBetaalgegevensForm(${JSON.stringify(a)})'>Submit payment details</button> `;
    if (isFinancien && a.status === 'goedgekeurd' && a.betaalgegevens) acties += `<button class="primary" onclick="markeerUitbetaald('${a.id}')">Mark as paid</button> `;
    if (isFinancien) acties += `<button class="secondary" onclick="verwijderAanvraag('${a.id}')">✕</button>`;

    return `<tr>
      <td style="cursor:pointer;" onclick="toonAanvraagGraph('${a.id}')">${escapeHtml(a.nummer || '—')}</td>
      <td>${a.type === 'declaratie' ? 'Reimbursement' : 'Order'}</td>
      <td>${escapeHtml(a.aanvragerNaam)}</td>
      <td>${escapeHtml(a.omschrijving)}${a.leverancier ? ' <span class="badge grijs">' + escapeHtml(a.leverancier) + '</span>' : ''} ${bijlagenLinks(a.bijlagen)}</td>
      <td>${euro(a.bedrag)}</td>
      <td><span class="badge ${statusBadge[a.status]}">${statusLabel[a.status] || a.status}</span>${a.betaalgegevens && a.status === 'goedgekeurd' ? ' <span class="badge geel">details received</span>' : ''}</td>
      <td style="white-space:nowrap;">${acties}</td>
    </tr>`;
  }).join('') || '<tr><td colspan="7">No requests yet.</td></tr>';
}

function openAanvraagForm(type, bestaandeAanvraag) {
  huidigAanvraagType = type;
  huidigeAanvraagIdBewerken = bestaandeAanvraag ? bestaandeAanvraag.id : null;
  bijlageA = bestaandeAanvraag ? [...(bestaandeAanvraag.bijlagen || [])] : [];
  document.getElementById('aanvraag-formulier-velden').style.display = 'block';
  document.getElementById('aanvraag-succes').style.display = 'none';
  document.getElementById('aanvraag-titel').textContent = bestaandeAanvraag
    ? (type === 'declaratie' ? 'Edit reimbursement' : 'Edit order request')
    : (type === 'declaratie' ? 'Submit reimbursement' : 'Submit order request');
  document.getElementById('a-leverancier-label').style.display = type === 'bestelling_aanvraag' ? 'block' : 'none';
  document.getElementById('a-leverancier').style.display = type === 'bestelling_aanvraag' ? 'block' : 'none';
  document.getElementById('a-bedrag').value = bestaandeAanvraag ? bestaandeAanvraag.bedrag : '';
  document.getElementById('a-datum').value = bestaandeAanvraag?.datum ? bestaandeAanvraag.datum : vandaagAlsDatum();
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
  const isoDatum = document.getElementById('a-datum').value;
  if (!isoDatum) { alert('Choose a date.'); return; }
  const body = {
    type: huidigAanvraagType,
    bedrag,
    datum: isoDatum,
    leverancier: document.getElementById('a-leverancier').value,
    omschrijving,
    bijlagen: bijlageA,
  };
  if (!body.bedrag || !body.omschrijving.trim()) { alert('Amount and description are required.'); return; }

  const url = huidigeAanvraagIdBewerken
    ? 'api_finance.php?action=aanvraag_update&id=' + encodeURIComponent(huidigeAanvraagIdBewerken)
    : 'api_finance.php?action=aanvraag_create';
  const res = await fetch(url, {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Save failed.'); return; }

  if (huidigeAanvraagIdBewerken) {
    sluitModal('modal-aanvraag');
    toonView('aanvragen');
    laadAanvragen();
    return;
  }

  const typeTekst = huidigAanvraagType === 'declaratie' ? 'reimbursement' : 'order request';
  const portaalLink = window.location.origin + window.location.pathname + '?tab=aanvragen';
  const bericht = `📋 New ${typeTekst} submitted: ${Number(bedrag).toFixed(2)} — ${omschrijving}\n\nView in the portal: ${portaalLink}`;
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
  await fetch('api_finance.php?action=aanvraag_besluit&id=' + encodeURIComponent(huidigeBesluitId), {
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
  if (!body.iban.trim()) { alert('IBAN / account number is required.'); return; }
  await fetch('api_finance.php?action=aanvraag_betaalgegevens_indienen&id=' + encodeURIComponent(huidigeBetaalId), {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  sluitModal('modal-betaalgegevens');
  laadAanvragen();
}

async function markeerUitbetaald(id) {
  if (!confirm('Mark as paid? This will automatically create an expense transaction.')) return;
  await fetch('api_finance.php?action=aanvraag_uitbetaald&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadAanvragen();
  laadTransacties();
  laadBalans();
}

async function verwijderAanvraag(id) {
  if (!confirm('Delete this request?')) return;
  await fetch('api_finance.php?action=aanvraag_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
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
        if (!confirm(`Import ${results.data.length} rows as settled reimbursements?`)) return;
        await fetch('api_finance.php?action=aanvraag_bulk_import', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ rijen: results.data }) });
        declaratieCsvInput.value = '';
        laadAanvragen();
        laadTransacties();
        laadBalans();
      },
    });
  });
}

// ---- Contacts (debtors/creditors) ----------------------------------------------------

async function laadRelaties() {
  const res = await fetch('api_finance.php?action=relatie_list');
  const data = await res.json();
  relatiesData = data.relaties;
  const typeLabel = { debiteur: 'Debtor', crediteur: 'Creditor', beide: 'Debtor & creditor' };
  document.getElementById('relaties-body').innerHTML = relatiesData.map(r => `
    <tr>
      <td>${escapeHtml(r.naam)}</td>
      <td>${typeLabel[r.type]}</td>
      <td>${escapeHtml(r.contactpersoon || '—')}</td>
      <td>${escapeHtml(r.email || '—')}</td>
      <td>${isFinancien ? `<button class="secondary" onclick='openRelatieForm(${JSON.stringify(r)})'>Edit</button> <button class="secondary" onclick="relatieVerwijderen('${r.id}')">✕</button>` : ''}</td>
    </tr>`).join('') || '<tr><td colspan="5">No contacts yet.</td></tr>';
}

let huidigeRelatieId = null;
function openRelatieForm(r) {
  huidigeRelatieId = r?.id || null;
  document.getElementById('relatie-titel').textContent = r ? 'Edit contact' : 'Add contact';
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
  if (!body.naam.trim()) { alert('Name is required.'); return; }
  const res = await fetch('api_finance.php?action=relatie_upsert', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!res.ok) { alert('Save failed.'); return; }
  sluitModal('modal-relatie');
  laadRelaties();
}

async function relatieVerwijderen(id) {
  if (!confirm('Delete this contact?')) return;
  await fetch('api_finance.php?action=relatie_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadRelaties();
}

// ---- Resales & on-account purchases ----------------------------------------------------

async function laadVoorraadFin() {
  const res = await fetch('api_print.php?action=voorraad_list');
  const data = await res.json();
  voorraadDataFin = data.voorraad;
}

async function laadPosten() {
  const res = await fetch('api_finance.php?action=post_list');
  const data = await res.json();
  postenData = data.posten;
  const statusBadge = { open: 'geel', deels_afgehandeld: 'geel', afgehandeld: 'groen' };

  document.getElementById('posten-body').innerHTML = postenData.map(p => `
    <tr>
      <td style="cursor:pointer;" onclick="toonPostGraph('${p.id}')">${escapeHtml(p.nummer)}</td>
      <td>Resale</td>
      <td>${escapeHtml(p.relatieNaam)}</td>
      <td style="font-size:12.5px;">${p.regels.map(r => escapeHtml(r.omschrijving) + (r.prive ? ' <span class="badge grijs" style="font-size:9.5px;">private</span>' : '')).join('<br>')}</td>
      <td>${euro(p.totaalbedrag)}</td>
      <td>${euro(p.totaalbedrag - p.afgehandeldBedrag)}</td>
      <td><span class="badge ${statusBadge[p.status]}">${p.status.replace('_', ' ')}</span></td>
      <td style="white-space:nowrap;">
        ${(isFinancien || p.createdBy === eigenUserNaam) && p.status === 'open' && p.afgehandeldBedrag === 0 ? `<button class="secondary" onclick='openPostForm(${JSON.stringify(p)})'>Edit</button> ` : ''}
        ${isFinancien && p.status !== 'afgehandeld' ? `<button class="secondary" onclick='openAfhandelenForm(${JSON.stringify(p)})'>Settle</button> ` : ''}
        ${isFinancien ? `<button class="secondary" style="color:#c9432f; border-color:#c9432f;" onclick="verwijderPost('${p.id}', ${p.afgehandeldBedrag > 0})">✕</button>` : ''}
      </td>
    </tr>`).join('') || '<tr><td colspan="8">No resales yet.</td></tr>';
}

async function verwijderPost(id, isAfgehandeld) {
  const waarschuwing = isAfgehandeld
    ? 'This resale has been (partially) settled — deleting it also removes the matching income booking(s) from the ledger. Are you sure?'
    : 'Delete this resale? The stock mutation of its lines will be reversed.';
  if (!confirm(waarschuwing)) return;
  await fetch('api_finance.php?action=post_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadPosten();
  laadTransacties();
  laadBalans();
}

let huidigePostId = null;

async function openPostForm(bestaandePost) {
  huidigePostId = bestaandePost ? bestaandePost.id : null;
  bijlageP = bestaandePost ? [...(bestaandePost.bijlagen || [])] : [];
  document.getElementById('post-titel').textContent = bestaandePost ? 'Edit resale — ' + bestaandePost.nummer : 'Create resale';
  document.getElementById('p-datum').value = bestaandePost?.datum ? bestaandePost.datum : vandaagAlsDatum();
  document.getElementById('p-factuur-velden').style.display = 'none';
  document.getElementById('p-factuurnummer').value = bestaandePost ? (bestaandePost.factuurnummer || '') : '';
  document.getElementById('p-bijlage').value = '';
  document.getElementById('p-bijlage-status').textContent = '';
  renderBijlagenBeheer('p-bijlagen-lijst', bijlageP, 'verwijderBijlageP');
  if (!voorraadDataFin.length) await laadVoorraadFin();
  document.getElementById('p-relatie').innerHTML = relatiesData
    .filter(r => r.type === 'beide' || r.type === 'debiteur')
    .map(r => `<option value="${r.id}"${bestaandePost && r.id === bestaandePost.relatieId ? ' selected' : ''}>${escapeHtml(r.naam)}</option>`).join('') || '<option value="">Add a contact first</option>';

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
  const opties = voorraadDataFin.map(v => `<option value="${escapeHtml(v.naam)}" data-waarde="${v.waardePerStuk ?? 0}" ${bestaandeKoppeling?.itemNaam === v.naam ? 'selected' : ''}>${escapeHtml(v.naam)} (stock: ${v.huidigeVoorraad})</option>`).join('');
  const idx = Date.now() + Math.random();
  const heeftBestaande = !!bestaandeKoppeling;
  const html = `
    <div class="checkbox-row" data-regel-type="voorraad" data-regel-id="${idx}">
      <select class="p-regel-item" onchange="bijwerkenPostTotaal()" style="flex:2;">${opties}</select>
      <input type="number" class="p-regel-aantal" min="1" value="${bestaandeKoppeling?.aantal ?? 1}" style="width:60px;" onchange="bijwerkenPostTotaal()" />
      <label style="display:flex; align-items:center; gap:2px; font-size:10.5px; color:var(--grey); white-space:nowrap; margin:0;">
        <input type="checkbox" class="p-regel-prijs-aan" style="width:auto;" ${heeftBestaande ? 'checked' : ''} onchange="this.closest('[data-regel-id]').querySelector('.p-regel-prijs-veld').style.display = this.checked ? 'inline-block' : 'none'; bijwerkenPostTotaal();" />adjusted price
      </label>
      <input type="number" class="p-regel-prijs-veld" min="0" step="0.0001" placeholder="per unit" value="${bestaandeKoppeling?.waardePerStuk ?? ''}" style="width:75px; display:${heeftBestaande ? 'inline-block' : 'none'};" onchange="bijwerkenPostTotaal()" />
      <span class="p-regel-subtotaal" style="width:80px; text-align:right; font-size:12.5px;">0.00</span>
      <button type="button" class="secondary" onclick="this.closest('[data-regel-id]').remove(); bijwerkenPostTotaal();">✕</button>
    </div>`;
  document.getElementById('p-regels-lijst').insertAdjacentHTML('beforeend', html);
  bijwerkenPostTotaal();
}

function voegVrijeRegelToe(bestaandeRegel) {
  const idx = Date.now();
  document.getElementById('p-regels-lijst').insertAdjacentHTML('beforeend', `
    <div class="checkbox-row" data-regel-type="vrij" data-regel-id="${idx}">
      <input class="p-regel-omschrijving" placeholder="Description (e.g. shipping costs)" value="${escapeHtml(bestaandeRegel?.omschrijving || '')}" style="flex:2;" />
      <input type="number" class="p-regel-bedrag" min="0" step="0.01" placeholder="amount" value="${bestaandeRegel?.bedrag ?? ''}" style="width:90px;" onchange="bijwerkenPostTotaal()" />
      <label style="display:flex; align-items:center; gap:2px; font-size:10.5px; color:var(--grey); white-space:nowrap; margin:0;" title="This cost is settled outside the club treasury (e.g. someone pays and collects it themselves directly) — doesn't count towards what the district still needs to collect.">
        <input type="checkbox" class="p-regel-prive" style="width:auto;" ${bestaandeRegel?.prive ? 'checked' : ''} onchange="bijwerkenPostTotaal()" />handled privately
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
      const prive = rij.querySelector('.p-regel-prive')?.checked;
      const regelBedrag = parseFloat(rij.querySelector('.p-regel-bedrag').value) || 0;
      if (!prive) totaal += regelBedrag;
    }
  });
  document.getElementById('p-totaal').textContent = euro(totaal);
}

async function postOpslaan() {
  const isoDatum = document.getElementById('p-datum').value;
  if (!isoDatum) { alert('Choose a date.'); return; }
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
  if (!body.relatieId) { alert('Choose a contact.'); return; }
  const url = huidigePostId
    ? 'api_finance.php?action=post_update&id=' + encodeURIComponent(huidigePostId)
    : 'api_finance.php?action=post_create';
  const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Save failed.'); return; }
  sluitModal('modal-post');
  laadPosten();
  laadTransacties();
  laadBalans();
  laadDerving();
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
  if (!regelIndexen.length) { alert('Choose at least 1 line.'); return; }
  const res = await fetch('api_finance.php?action=post_afhandelen&id=' + encodeURIComponent(huidigeAfhandelPostId), {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ regelIndexen }),
  });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Settling failed.'); return; }
  sluitModal('modal-post-afhandelen');
  laadPosten();
  laadTransacties();
  laadBalans();
}

// ---- Shrinkage ----------------------------------------------------

let bijlageD = [];

async function laadDerving() {
  const res = await fetch('api_finance.php?action=derving_list');
  const data = await res.json();
  const redenLabel = { miskoop: 'Mis-purchase', verloren_zending: 'Lost shipment', schade: 'Damage', overig: 'Other' };
  document.getElementById('derving-body').innerHTML = data.derving.map(d => `
    <tr>
      <td>${formatDatum(d.createdAt)}</td>
      <td>${escapeHtml(d.itemNaam)}</td>
      <td>${d.aantal}</td>
      <td><span class="badge rood">${redenLabel[d.reden]}</span></td>
      <td>${euro(d.verlies)}</td>
      <td>${escapeHtml(d.toelichting || '—')} ${bijlagenLinks(d.bijlagen)}</td>
      <td>${magDerving ? `<button class="secondary" onclick="dervingVerwijderen('${d.id}')">✕</button>` : ''}</td>
    </tr>`).join('') || '<tr><td colspan="7">No shrinkage recorded yet.</td></tr>';
}

async function openDervingForm() {
  bijlageD = [];
  if (!voorraadDataFin.length) await laadVoorraadFin();
  document.getElementById('d-item').innerHTML = voorraadDataFin.map(v => `<option value="${escapeHtml(v.naam)}" data-waarde="${v.waardePerStuk ?? 0}" data-voorraad="${v.huidigeVoorraad}">${escapeHtml(v.naam)} (stock: ${v.huidigeVoorraad})</option>`).join('');
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
  document.getElementById('d-verlies-preview').textContent = `Loss: ${euro(aantal * waarde)} (of ${sel.dataset.voorraad} in stock)`;
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
  const res = await fetch('api_finance.php?action=derving_create', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Recording failed.'); return; }
  sluitModal('modal-derving');
  laadDerving();
  laadTransacties();
  laadBalans();
}

async function dervingVerwijderen(id) {
  if (!confirm('Delete this shrinkage entry? Note: the linked expense transaction and stock mutation are NOT automatically reversed.')) return;
  await fetch('api_finance.php?action=derving_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadDerving();
}

// ---- Storage management ----------------------------------------------------

async function laadOpslagStatus() {
  const el = document.getElementById('opslag-status');
  if (!el) return;
  const res = await fetch('api_finance.php?action=opslag_status');
  if (!res.ok) { el.textContent = 'Could not retrieve storage status.'; return; }
  const data = await res.json();
  if (data.limietMB > 0) {
    const pct = Math.min(100, Math.round((data.gebruiktMB / data.limietMB) * 100));
    el.textContent = `${data.gebruiktMB} MB of ${data.limietMB} MB used (${pct}%)`;
    const balk = document.getElementById('opslag-balk');
    balk.style.width = pct + '%';
    balk.style.background = pct >= 90 ? '#c9432f' : (pct >= 70 ? '#d4a417' : 'var(--primary)');
  } else {
    el.textContent = `${data.gebruiktMB} MB used (no limit set)`;
  }
}

function opslagBackupDownloaden() {
  window.location.href = 'api_finance.php?action=bijlagen_backup_downloaden';
}

async function opslagWissenNaBackup() {
  if (!confirm('Have you already downloaded and safely stored the backup zip?\n\nClicking OK will permanently delete all locally stored attachments from the server. The records (amounts, descriptions) stay in place, but the files themselves will then only be found in the backup.')) return;
  const res = await fetch('api_finance.php?action=bijlagen_wissen_na_backup', { method: 'POST', credentials: 'same-origin' });
  const data = await res.json();
  alert(data.verwijderd + ' file(s) removed from the server.');
  laadOpslagStatus();
  laadTransacties();
  laadAanvragen();
  laadPosten();
  laadDerving();
}

// ---- Joint purchases ("follow the money") ----------------------------------------------------

let reroutesData = [];

let huidigeRerouteId = null;

async function laadReroutes() {
  const res = await fetch('api_finance.php?action=reroute_list');
  const data = await res.json();
  reroutesData = data.reroutes;
  laadTransacties();
  document.getElementById('reroutes-body').innerHTML = reroutesData.map(r => {
    const totaalUit = r.totaalAankopen + (r.totaalBijkomendeKosten || 0);
    const verschil = Math.round((r.totaalBijdragen - totaalUit) * 100) / 100;
    const aantalUit = r.aankopen.length + (r.bijkomendeKosten?.length || 0);
    return `<tr>
      <td style="cursor:pointer;" onclick='toonRerouteGraph(${JSON.stringify(r.id)})'>${escapeHtml(r.nummer)}</td>
      <td style="cursor:pointer;" onclick='toonRerouteGraph(${JSON.stringify(r.id)})'>${escapeHtml(r.titel)}</td>
      <td style="cursor:pointer;" onclick='toonRerouteGraph(${JSON.stringify(r.id)})'>${euro(r.totaalBijdragen)} <span style="color:var(--grey); font-size:11.5px;">(${r.bijdragen.length}x)</span></td>
      <td style="cursor:pointer;" onclick='toonRerouteGraph(${JSON.stringify(r.id)})'>${euro(totaalUit)} <span style="color:var(--grey); font-size:11.5px;">(${aantalUit}x)</span></td>
      <td style="cursor:pointer;" onclick='toonRerouteGraph(${JSON.stringify(r.id)})'>${verschil === 0 ? '<span class="badge groen">balanced</span>' : `<span class="badge ${verschil > 0 ? 'geel' : 'rood'}">${euro(Math.abs(verschil))} ${verschil > 0 ? 'surplus' : 'shortfall'}</span>`}</td>
      <td style="white-space:nowrap;">
        ${isFinancien ? `<button class="secondary" onclick='openRerouteForm(${JSON.stringify(r)})'>Edit</button> <button class="secondary" onclick="verwijderReroute('${r.id}')">✕</button>` : ''}
      </td>
    </tr>`;
  }).join('') || '<tr><td colspan="6">No joint purchases yet.</td></tr>';
}

async function openRerouteForm(bestaandeReroute) {
  await laadGebruikersLijst();
  huidigeRerouteId = bestaandeReroute ? bestaandeReroute.id : null;
  document.getElementById('ri-titel-kop').textContent = bestaandeReroute ? 'Edit joint purchase — ' + bestaandeReroute.nummer : 'New joint purchase';
  document.getElementById('ri-titel').value = bestaandeReroute ? bestaandeReroute.titel : '';
  document.getElementById('ri-datum').value = bestaandeReroute?.datum ? bestaandeReroute.datum : vandaagAlsDatum();
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
  const res = await fetch('api_finance.php?action=gebruikers_lijst');
  const data = await res.json();
  gebruikersLijst = data.gebruikers;
}

function voegRerouteRegelToe(soort, bestaandeRegel) {
  const idx = Date.now() + Math.random();
  const placeholders = {
    bijdragen: 'Who is contributing (e.g. North Region)',
    aankopen: 'Where to (e.g. Onlineprinters.com)',
    bijkomendeKosten: 'Description (e.g. shipping to North Region)',
  };
  const bestaandeBijlageJson = bestaandeRegel?.bijlagen?.[0] ? JSON.stringify(bestaandeRegel.bijlagen[0]).replace(/"/g, '&quot;') : '';
  const gefactureerdOpties = gebruikersLijst.map(u => `<option value="${u.id}" ${bestaandeRegel?.gekoppeldeAanvraagId && bestaandeRegel?.gefactureerdDoor === u.naam ? 'selected' : ''}>${escapeHtml(u.naam)}</option>`).join('');
  const gefactureerdDoorVeld = soort === 'bijkomendeKosten' ? `
      <input class="ri-regel-gefactureerd" placeholder="Invoiced/advanced by (free text)" value="${escapeHtml(bestaandeRegel?.gefactureerdDoor || '')}" style="flex:1.2;" />
      <select class="ri-regel-voorschot-user" style="flex:1.1;" title="Choose a registered member if this is a reimbursable advance — automatically creates a reimbursement request for that person">
        <option value="">— no reimbursement —</option>
        ${gefactureerdOpties}
      </select>` : '';
  const voorschotBadge = bestaandeRegel?.gekoppeldeAanvraagId
    ? `<span class="badge geel" style="white-space:nowrap;" title="Already running as a reimbursement — stays unchanged when saving again" data-bestaande-aanvraag-id="${bestaandeRegel.gekoppeldeAanvraagId}">${escapeHtml(bestaandeRegel.aanvraagNummer || 'reimbursement')}</span>`
    : '';
  const eigenInlegVeld = soort === 'bijdragen'
    ? `<label style="display:flex; align-items:center; gap:3px; font-size:11px; color:var(--grey); white-space:nowrap; margin:0;"><input type="checkbox" class="ri-regel-eigeninleg" style="width:auto;" ${bestaandeRegel?.eigenInleg ? 'checked' : ''} />own contribution</label>`
    : '';
  document.getElementById('ri-' + soort + '-lijst').insertAdjacentHTML('beforeend', `
    <div class="checkbox-row" data-regel-id="${idx}">
      <input class="ri-regel-naam" placeholder="${placeholders[soort]}" value="${escapeHtml(bestaandeRegel?.naam || '')}" style="flex:2;" />
      <input type="number" class="ri-regel-bedrag" min="0" step="0.01" placeholder="amount" value="${bestaandeRegel?.bedrag ?? ''}" style="width:90px;" onchange="bijwerkenRerouteTotalen()" ${bestaandeRegel?.gekoppeldeAanvraagId ? 'disabled title="Amount is already fixed in the linked reimbursement"' : ''} />
      ${gefactureerdDoorVeld}
      ${voorschotBadge}
      ${eigenInlegVeld}
      <input type="file" class="ri-regel-bijlage" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" onchange="rerouteRegelBijlageUpload(this)" data-geupload-json="${bestaandeBijlageJson}" />
      <button type="button" class="secondary" onclick="this.previousElementSibling.click()">📎${bestaandeRegel?.bijlagen?.length ? ' ✓' : ''}</button>
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
  if (verschil === 0) { verschilEl.textContent = '✓ Contributions and expenses (purchases + costs) are balanced.'; verschilEl.style.color = 'var(--status-groen)'; }
  else { verschilEl.textContent = (verschil > 0 ? '⚠ ' + verschil.toFixed(2) + ' more contributed than spent.' : '⚠ ' + Math.abs(verschil).toFixed(2) + ' more spent than contributed.'); verschilEl.style.color = 'var(--status-oranje)'; }
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
      regel.bestaandeGekoppeldeAanvraagId = bestaandeVoorschotBadge.dataset.bestaandeAanvraagId;
    } else if (voorschotVeld && voorschotVeld.value) {
      regel.voorschotAanvragerId = voorschotVeld.value;
    }
    if (eigenInlegVeld) regel.eigenInleg = eigenInlegVeld.checked;
    return regel;
  }).filter(r => r.naam.trim() && r.bedrag > 0);
}

async function rerouteOpslaan() {
  const isoDatum = document.getElementById('ri-datum').value;
  if (!isoDatum) { alert('Choose a date.'); return; }
  const body = {
    titel: document.getElementById('ri-titel').value,
    datum: isoDatum,
    bijdragen: verzamelRerouteRegels('ri-bijdragen-lijst'),
    aankopen: verzamelRerouteRegels('ri-aankopen-lijst'),
    bijkomendeKosten: verzamelRerouteRegels('ri-bijkomendeKosten-lijst'),
  };
  if (!body.titel.trim()) { alert('Title is required.'); return; }
  if (!body.bijdragen.length || !body.aankopen.length) { alert('Add at least 1 contribution and 1 purchase.'); return; }
  const url = huidigeRerouteId
    ? 'api_finance.php?action=reroute_update&id=' + encodeURIComponent(huidigeRerouteId)
    : 'api_finance.php?action=reroute_create';
  const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Save failed.'); return; }
  sluitModal('modal-reroute');
  laadReroutes();
  laadTransacties();
  laadBalans();
  alert((huidigeRerouteId ? 'Updated: ' : 'Created: ') + data.reroute.nummer);
}

async function verwijderReroute(id) {
  if (!confirm('Delete this joint purchase? All linked bookings (contributions, purchases, and additional costs) will also disappear from the ledger.')) return;
  await fetch('api_finance.php?action=reroute_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadReroutes();
  laadTransacties();
  laadBalans();
}

// ---- Visual "follow the money" chart ----------------------------------------------------

function toonRerouteGraph(id) {
  const r = reroutesData.find(x => x.id === id);
  if (!r) return;
  const linksItems = r.bijdragen.map(b => ({
    naam: b.naam, bedrag: b.bedrag,
    kleur: b.eigenInleg ? '#3b6fa0' : 'var(--status-groen)',
    achtergrond: b.eigenInleg ? '#e3ecf5' : '#e6f4ea',
    subtekst: b.eigenInleg ? 'own contribution' : (b.bijlagen?.length ? '📎' : ''),
    gestippeld: !!b.eigenInleg,
  }));
  const rechtsItems = [
    ...r.aankopen.map(a => ({ naam: a.naam, bedrag: a.bedrag, kleur: 'var(--status-rood)', achtergrond: '#f9e3df', subtekst: a.bijlagen?.length ? '📎' : '' })),
    ...(r.bijkomendeKosten || []).map(k => ({
      naam: k.naam, bedrag: k.bedrag,
      kleur: k.gekoppeldeAanvraagId ? '#a3760f' : 'var(--status-oranje)',
      achtergrond: k.gekoppeldeAanvraagId ? '#fdf3df' : '#fbe9dc',
      subtekst: k.gekoppeldeAanvraagId ? '📋 ' + (k.aanvraagNummer || 'reimbursement') : (k.gefactureerdDoor ? afkappen(k.gefactureerdDoor, 16) : (k.bijlagen?.length ? '📎' : '')),
    })),
  ];

  let details = `<div class="card" style="background:var(--primary-light-95); border:none;">`;
  details += `<p style="margin:0 0 8px;">${r.datum ? '<strong>Date:</strong> ' + formatDatum(r.datum) : ''}</p>`;
  details += `<p style="margin:0 0 4px;"><strong>Contributions:</strong></p><ul style="margin:0 0 8px; padding-left:18px; font-size:13px;">`;
  r.bijdragen.forEach(b => { details += `<li>${escapeHtml(b.naam)} — ${euro(b.bedrag)}${b.eigenInleg ? ' <span class="badge grijs">own contribution</span>' : ''} ${bijlagenLinks(b.bijlagen)}</li>`; });
  details += `</ul><p style="margin:0 0 4px;"><strong>Purchases:</strong></p><ul style="margin:0 0 8px; padding-left:18px; font-size:13px;">`;
  r.aankopen.forEach(a => { details += `<li>${escapeHtml(a.naam)} — ${euro(a.bedrag)} ${bijlagenLinks(a.bijlagen)}</li>`; });
  details += `</ul>`;
  if (r.bijkomendeKosten?.length) {
    details += `<p style="margin:0 0 4px;"><strong>Additional costs:</strong></p><ul style="margin:0 0 8px; padding-left:18px; font-size:13px;">`;
    r.bijkomendeKosten.forEach(k => { details += `<li>${escapeHtml(k.naam)} — ${euro(k.bedrag)}${k.gefactureerdDoor ? ' — invoiced by ' + escapeHtml(k.gefactureerdDoor) : ''}${k.gekoppeldeAanvraagId ? ' <span class="badge geel">via reimbursement ' + escapeHtml(k.aanvraagNummer || '') + '</span>' : ''} ${bijlagenLinks(k.bijlagen)}</li>`; });
    details += `</ul>`;
  }
  details += `<p style="margin:8px 0 0; font-size:12px; color:var(--grey);">Created by ${escapeHtml(r.createdBy)}</p>`;
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
    subtekst: openstaand > 0.005 ? euro(openstaand) + ' still outstanding' : 'fully paid',
  }];
  const rechtsItems = p.regels.map(r => ({
    naam: r.omschrijving, bedrag: r.bedrag,
    kleur: r.voorraadKoppeling ? 'var(--status-rood)' : (r.prive ? 'var(--status-grijs)' : 'var(--status-oranje)'),
    achtergrond: r.voorraadKoppeling ? '#f9e3df' : (r.prive ? '#eef0ef' : '#fbe9dc'),
    subtekst: r.voorraadKoppeling ? (r.voorraadKoppeling.aantal + 'x') : (r.prive ? 'private, outside the ledger' : ''),
  }));

  let details = `<div class="card" style="background:var(--primary-light-95); border:none;">`;
  details += `<p style="margin:0 0 8px;"><strong>Status:</strong> <span class="badge ${p.status === 'afgehandeld' ? 'groen' : 'geel'}">${escapeHtml(p.status.replace('_', ' '))}</span> — ${euro(p.afgehandeldBedrag)} of ${euro(p.totaalbedrag)} settled${p.datum ? ' — date: ' + formatDatum(p.datum) : ''}</p>`;
  details += `<p style="margin:0 0 8px;"><strong>All lines:</strong></p><ul style="margin:0 0 8px; padding-left:18px; font-size:13px;">`;
  p.regels.forEach(r => {
    details += `<li>${escapeHtml(r.omschrijving)} — ${euro(r.bedrag)}${r.prive ? ' <span class="badge grijs">private, not booked</span>' : ''} ${bijlagenLinks(r.bijlagen)}</li>`;
  });
  details += `</ul>`;
  if (p.afhandelingen?.length) {
    details += `<p style="margin:0 0 4px;"><strong>Settlements:</strong></p><ul style="margin:0 0 8px; padding-left:18px; font-size:13px;">`;
    p.afhandelingen.forEach(a => { details += `<li>${formatDatum(a.datum)} — ${euro(a.bedrag)} — ${escapeHtml(a.omschrijving)} (by ${escapeHtml(a.door)})</li>`; });
    details += `</ul>`;
  }
  if (p.factuurnummer) details += `<p style="margin:0; font-size:13px;"><strong>Invoice number:</strong> ${escapeHtml(p.factuurnummer)}</p>`;
  if (p.bijlagen?.length) details += `<p style="margin:4px 0 0; font-size:13px;"><strong>Entry attachments:</strong> ${bijlagenLinks(p.bijlagen)}</p>`;
  details += `<p style="margin:8px 0 0; font-size:12px; color:var(--grey);">Created by ${escapeHtml(p.createdBy)}</p>`;
  details += `</div>`;

  toonFlowGraph(p.nummer + ' — ' + p.relatieNaam, p.nummer, p.totaalbedrag, linksItems, rechtsItems, details);
}

function toonAanvraagGraph(id) {
  const a = aanvragenData.find(x => x.id === id);
  if (!a) return;
  const linksItems = [{ naam: a.aanvragerNaam, bedrag: a.bedrag, kleur: 'var(--status-groen)', achtergrond: '#e6f4ea', subtekst: a.bijlagen?.length ? '📎 proof of payment' : '' }];
  const statusInfo = {
    open: { label: 'In review', kleur: 'var(--status-geel)', achtergrond: '#fbf1da' },
    goedgekeurd: { label: 'Approved', kleur: 'var(--status-geel)', achtergrond: '#fbf1da' },
    afgewezen: { label: 'Rejected', kleur: 'var(--status-rood)', achtergrond: '#f9e3df' },
    uitbetaald: { label: 'Paid', kleur: 'var(--status-rood)', achtergrond: '#f9e3df' },
  }[a.status];
  const rechtsItems = [{ naam: statusInfo.label, bedrag: a.bedrag, kleur: statusInfo.kleur, achtergrond: statusInfo.achtergrond, subtekst: a.type === 'declaratie' ? 'reimbursement' : 'order' }];

  let details = `<div class="card" style="background:var(--primary-light-95); border:none;">`;
  details += `<p style="margin:0 0 8px;"><strong>Status:</strong> <span class="badge ${a.status === 'uitbetaald' || a.status === 'goedgekeurd' ? 'groen' : (a.status === 'afgewezen' ? 'rood' : 'geel')}">${escapeHtml(a.status)}</span>${a.datum ? ' — date: ' + formatDatum(a.datum) : ''}</p>`;
  details += `<p style="margin:0 0 8px; font-size:13px;"><strong>Description:</strong> ${escapeHtml(a.omschrijving)}</p>`;
  if (a.leverancier) details += `<p style="margin:0 0 8px; font-size:13px;"><strong>Supplier:</strong> ${escapeHtml(a.leverancier)}</p>`;
  if (a.bijlagen?.length) details += `<p style="margin:0 0 8px; font-size:13px;"><strong>Attachments:</strong> ${bijlagenLinks(a.bijlagen)}</p>`;
  if (a.opmerkingPenningmeester) details += `<p style="margin:0 0 8px; font-size:13px;"><strong>Treasurer's note:</strong> ${escapeHtml(a.opmerkingPenningmeester)}</p>`;
  if (a.betaalgegevens) details += `<p style="margin:0 0 8px; font-size:13px;"><strong>Payment details:</strong> ${escapeHtml(a.betaalgegevens.iban)}, account holder ${escapeHtml(a.betaalgegevens.tenaamstelling)}</p>`;
  details += `<p style="margin:8px 0 0; font-size:12px; color:var(--grey);">Submitted by ${escapeHtml(a.aanvragerNaam)}${a.behandeldDoor ? ' — reviewed by ' + escapeHtml(a.behandeldDoor) : ''}</p>`;
  details += `</div>`;

  toonFlowGraph((a.nummer || '') + ' — ' + a.aanvragerNaam, a.nummer || '', a.bedrag, linksItems, rechtsItems, details);
}

function toonFlowGraph(titel, poolNummer, poolTotaal, linksItems, rechtsItems, detailsHtml) {
  document.getElementById('rg-titel').textContent = titel;
  document.getElementById('rg-svg-container').innerHTML = flowSvg(poolNummer, poolTotaal, linksItems, rechtsItems);
  document.getElementById('rg-details').innerHTML = detailsHtml || '';
  document.getElementById('modal-flow-graph').style.display = 'flex';
}

function flowSvg(poolNummer, poolTotaal, linksItems, rechtsItems) {
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
    svg += `<circle cx="${linksX}" cy="${y}" r="42" fill="${item.achtergrond}" stroke="${item.kleur}" stroke-width="1.5" />`;
    svg += `<text x="${linksX}" y="${y - 4}" text-anchor="middle" font-size="11" fill="var(--ink)">${escapeHtml(afkappen(item.naam, 14))}</text>`;
    svg += `<text x="${linksX}" y="${y + 12}" text-anchor="middle" font-size="12" font-weight="700" fill="${item.kleur}">${euro(item.bedrag)}</text>`;
    if (item.subtekst) svg += `<text x="${linksX}" y="${y + 26}" text-anchor="middle" font-size="9.5" fill="var(--grey)">${escapeHtml(item.subtekst)}</text>`;
  });

  rechtsItems.forEach((item, i) => {
    const y = rechtsStartY + i * rijHoogte;
    svg += `<circle cx="${rechtsX}" cy="${y}" r="42" fill="${item.achtergrond}" stroke="${item.kleur}" stroke-width="1.5" />`;
    svg += `<text x="${rechtsX}" y="${y - 4}" text-anchor="middle" font-size="11" fill="var(--ink)">${escapeHtml(afkappen(item.naam, 14))}</text>`;
    svg += `<text x="${rechtsX}" y="${y + 12}" text-anchor="middle" font-size="12" font-weight="700" fill="${item.kleur}">${euro(item.bedrag)}</text>`;
    if (item.subtekst) svg += `<text x="${rechtsX}" y="${y + 26}" text-anchor="middle" font-size="9.5" fill="var(--grey)">${escapeHtml(item.subtekst)}</text>`;
  });

  svg += '</svg>';
  return svg;
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
