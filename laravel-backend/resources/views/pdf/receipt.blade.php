<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Quittance {{ $payment->receipt_number }}</title>
</head>
<body>
  @include('pdf.partials.frame')

  <div class="title">QUITTANCE DE PAIEMENT DE SOINS</div>
  <div class="ref">N° <strong>{{ $payment->receipt_number }}</strong> · Facture {{ $invoiceNumber }} · le {{ $payment->payment_datetime->format('d/m/Y à H:i') }}</div>

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
    <tr>
      <td>
        <span class="muted">Médecin traitant</span><br>
        {{ $doctorLabel ?? 'Non précisé' }}
      </td>
      <td class="right">
        <span class="muted">N° d’ordre</span><br>{{ $doctorLicence ?? 'à renseigner dans le profil du médecin' }}
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
          <td class="num">{{ \App\Services\Receipts\ReceiptPdf::money((float) $item->amount) }}</td>
        </tr>
      @empty
        <tr><td colspan="2" class="muted">Aucun acte détaillé.</td></tr>
      @endforelse
    </tbody>
  </table>

  <table style="margin-top: 8px;">
    <tr class="total"><td>Total brut</td><td class="num">{{ \App\Services\Receipts\ReceiptPdf::money($total) }}</td></tr>
    @if ($coverage['rate'] !== null)
      <tr><td>Part assurance ({{ rtrim(rtrim(number_format($coverage['rate'], 2, ',', ''), '0'), ',') }} %)</td><td class="num">{{ \App\Services\Receipts\ReceiptPdf::money($coverage['insurer_share']) }}</td></tr>
      <tr><td>Ticket modérateur patient</td><td class="num">{{ \App\Services\Receipts\ReceiptPdf::money($coverage['patient_share']) }}</td></tr>
    @endif
    <tr><td>Ce règlement</td><td class="num">{{ \App\Services\Receipts\ReceiptPdf::money((float) $payment->amount) }}</td></tr>
    <tr><td>Acomptes versés</td><td class="num">{{ \App\Services\Receipts\ReceiptPdf::money($paidToDate) }}</td></tr>
    <tr><td>Mode de paiement</td><td class="right">{{ $method }}{{ $payment->payment_reference ? ' · réf. '.$payment->payment_reference : '' }}{{ $payment->insurer_name ? ' · '.$payment->insurer_name : '' }}</td></tr>
    @if ($coverage['rate'] !== null)
      <tr><td>Dont ticket modérateur patient restant</td><td class="num">{{ \App\Services\Receipts\ReceiptPdf::money($coverage['patient_remaining']) }}</td></tr>
    @endif
    <tr>
      <td><strong>Reste à payer</strong></td>
      <td class="num">
        <strong>{{ \App\Services\Receipts\ReceiptPdf::money($remaining) }}</strong>
        @if ($settled)
          <span class="badge solde">SOLDÉ</span>
        @else
          <span class="badge reste">RESTE À PAYER</span>
        @endif
      </td>
    </tr>
  </table>

  <table style="margin-top: 12px;">
    <tr>
      <td class="muted" style="width: 55%;">Le présent reçu atteste du règlement des soins ci-dessus. Il ne vaut pas prescription médicale.</td>
      <td>
        <div class="muted">Cachet de la caisse et signature de l’agent</div>
        <div class="sign"></div>
      </td>
    </tr>
  </table>
</body>
</html>
