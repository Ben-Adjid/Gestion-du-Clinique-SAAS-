<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
  body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #0f172a; margin: 0; }
  .head { border-bottom: 2px solid #047857; padding-bottom: 8px; }
  .clinic { font-size: 14px; font-weight: bold; color: #065f46; }
  .muted { color: #64748b; font-size: 9px; }
  .title { text-align: center; font-size: 13px; font-weight: bold; letter-spacing: 1px; margin: 12px 0 6px; }
  .ref { text-align: center; font-size: 9px; color: #475569; margin-bottom: 10px; }
  table { width: 100%; border-collapse: collapse; }
  td, th { padding: 4px 3px; vertical-align: top; }
  .box { border: 1px solid #cbd5e1; padding: 6px; }
  .items th { text-align: left; font-size: 9px; color: #475569; border-bottom: 1px solid #cbd5e1; }
  .items td { border-bottom: 1px solid #f1f5f9; }
  .right { text-align: right; }
  .total td { font-size: 11px; font-weight: bold; padding-top: 6px; }
  .badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 9px; }
  .solde { background: #d1fae5; color: #065f46; }
  .reste { background: #fef3c7; color: #92400e; }
  .sign { margin-top: 18px; height: 60px; border: 1px dashed #94a3b8; }
  .foot { margin-top: 6px; font-size: 8px; color: #64748b; text-align: center; }
</style>
</head>
<body>
  <div class="head">
    <table>
      <tr>
        <td>
          <div class="clinic">{{ $clinic->name }}</div>
          <div class="muted">{{ trim(($clinic->city ?? '').' '.($clinic->country ?? '')) }}</div>
          <div class="muted">{{ $clinic->settings['address'] ?? '' }}</div>
          <div class="muted">Tél. {{ $clinic->settings['phone'] ?? $clinic->admin_phone ?? '—' }}{{ $clinic->admin_email ? ' · '.$clinic->admin_email : '' }}</div>
        </td>
        <td class="right muted">
          Numéro d’agrément<br>
          <strong>{{ $licence ?: 'Non renseigné' }}</strong>
        </td>
      </tr>
    </table>
  </div>

  <div class="title">QUITTANCE DE PAIEMENT DE SOINS</div>
  <div class="ref">Référence <strong>{{ $payment->receipt_number }}</strong> · Facture {{ $invoiceNumber }} · le {{ $payment->payment_datetime->format('d/m/Y à H:i') }}</div>

  <table class="box">
    <tr>
      <td>
        <strong>Patient</strong><br>
        {{ strtoupper($patient->last_name) }} {{ $patient->first_name }}<br>
        <span class="muted">Dossier {{ $patient->patient_unique_id }}</span>
      </td>
      <td class="right">
        <span class="muted">Téléphone</span><br>{{ $patient->phone ?? '—' }}
      </td>
    </tr>
  </table>

  <table class="items" style="margin-top: 10px;">
    <thead>
      <tr>
        <th>Acte ou prestation</th>
        <th class="right">Montant</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($items as $item)
        <tr>
          <td>{{ $item->description }}</td>
          <td class="right">{{ \App\Services\Receipts\ReceiptPdf::money((float) $item->amount) }}</td>
        </tr>
      @empty
        <tr><td colspan="2" class="muted">Aucun acte détaillé.</td></tr>
      @endforelse
    </tbody>
  </table>

  <table style="margin-top: 8px;">
    <tr class="total"><td>Montant total</td><td class="right">{{ \App\Services\Receipts\ReceiptPdf::money($total) }}</td></tr>
    <tr><td>Ce règlement</td><td class="right">{{ \App\Services\Receipts\ReceiptPdf::money((float) $payment->amount) }}</td></tr>
    <tr><td>Montant réglé à ce jour</td><td class="right">{{ \App\Services\Receipts\ReceiptPdf::money($paidToDate) }}</td></tr>
    <tr><td>Mode de paiement</td><td class="right">{{ $method }}{{ $payment->payment_reference ? ' · réf. '.$payment->payment_reference : '' }}{{ $payment->insurer_name ? ' · '.$payment->insurer_name : '' }}</td></tr>
    <tr>
      <td>Reste à payer</td>
      <td class="right">
        {{ \App\Services\Receipts\ReceiptPdf::money($remaining) }}
        @if ($settled)
          <span class="badge solde">SOLDÉ</span>
        @else
          <span class="badge reste">RESTE À PAYER</span>
        @endif
      </td>
    </tr>
  </table>

  <table style="margin-top: 14px;">
    <tr>
      <td class="muted" style="width: 55%;">Le présent reçu atteste du règlement des soins ci-dessus. Il ne vaut pas prescription médicale.</td>
      <td>
        <div class="muted">Cachet de la caisse et signature de l’agent</div>
        <div class="sign"></div>
      </td>
    </tr>
  </table>

  <div class="foot">Document généré par Clinique+ · {{ $clinic->name }}</div>
</body>
</html>
