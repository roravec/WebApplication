<?php
namespace Panel\Invoicing;

/**
 * E-invoice: UBL 2.1 Invoice / CreditNote according to EN 16931 and Peppol BIS Billing 3.0 (the format of the Slovak
 * eFaktúra from 1. 1. 2027, zákon 385/2025 Z. z.). Only the document — sending through a Peppol access point
 * ("digitálny poštár") is a separate step once a provider is chosen per company.
 *
 * Versioned like other external formats (docs/legislativa.md): CUSTOMIZATION, PROFILE and the participant scheme are
 * constants here; a new version of the specification = a subclass / new constants, not edits of issued documents.
 * Slovak participant identifier: scheme 0245 + DIČ (10 digits, without "SK") — per providers' guides 2026-11-19,
 * verify against the Slovak PASR when the access point is set up.
 */
class Ubl
{
    const VERSION = 'peppol-bis-3.0';
    const CUSTOMIZATION = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';
    const PROFILE = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';
    const SCHEME_SK = '0245';
    /** our units → UN/ECE Recommendation 20 codes (unknown → C62 "one") */
    const UNITS = ['ks' => 'H87', 'kus' => 'H87', 'ks.' => 'H87', 'hod' => 'HUR', 'h' => 'HUR', 'deň' => 'DAY', 'dni' => 'DAY',
        'm' => 'MTR', 'm2' => 'MTK', 'm3' => 'MTQ', 'km' => 'KMT', 'kg' => 'KGM', 'g' => 'GRM', 't' => 'TNE', 'l' => 'LTR',
        'bal' => 'XPK', 'balenie' => 'XPK', 'súprava' => 'SET', 'set' => 'SET', 'mes' => 'MON', 'rok' => 'ANN'];
    /** our payment methods → UNCL4461 payment means */
    const PAYMENT_MEANS = ['transfer' => '58', 'card' => '48', 'cash' => '10', 'cod' => '10'];

    /** Reasons the document can't be an e-invoice (empty = OK). */
    public static function check(array $inv, array $supplier): array
    {
        $err = [];
        if ($inv['status'] !== 'issued') {
            $err[] = 'Doklad nie je vystavený.';
        }
        if (!preg_match('/^\d{10}$/', (string)$supplier['dic'])) {
            $err[] = 'Dodávateľ nemá DIČ (10 číslic) — je súčasťou Peppol adresy (0245:DIČ).';
        }
        if (!preg_match('/^\d{10}$/', (string)$inv['buyer_dic'])) {
            $err[] = 'Odberateľ nemá DIČ (10 číslic) — e-faktúra sa posiela firmám cez Peppol (0245:DIČ).';
        }
        if (trim((string)$inv['buyer_street'] . $inv['buyer_city']) === '') {
            $err[] = 'Odberateľ nemá adresu.';
        }
        if (!$inv['lines']) {
            $err[] = 'Doklad nemá položky.';
        }
        foreach ($inv['lines'] as $l) {
            if (in_array($l['vat_category'], ['AE', 'K'], true) && trim((string)$inv['buyer_icdph']) === '') {
                $err[] = 'Pri kategórii ' . $l['vat_category'] . ' musí mať odberateľ IČ DPH.';
                break;
            }
        }
        return $err;
    }

    /**
     * @param array $inv issued document (Invoices::find), $supplier its frozen supplier data, $legal frozen legal texts,
     *              $original the corrected invoice for a credit note
     */
    public static function build(array $inv, array $supplier, array $legal, ?array $original = null): string
    {
        $credit = $inv['doc_type'] === 'credit_note';
        $sign = $credit ? -1 : 1;            // a credit note carries positive amounts, the type says "credit"
        $cur = (string)$inv['currency'];
        $root = $credit ? 'CreditNote' : 'Invoice';
        $amt = function ($v) use ($sign) { return Calculator::fmt($sign * (float)$v); };

        $w = new \XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->setIndentString('  ');
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement($root);
        $w->writeAttribute('xmlns', 'urn:oasis:names:specification:ubl:schema:xsd:' . $root . '-2');
        $w->writeAttribute('xmlns:cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $w->writeAttribute('xmlns:cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $cbc = function (string $name, $value, array $attr = []) use ($w) {
            if ($value === null || $value === '') {
                return;
            }
            $w->startElement('cbc:' . $name);
            foreach ($attr as $k => $v) {
                $w->writeAttribute($k, $v);
            }
            $w->text((string)$value);
            $w->endElement();
        };
        $money = function (string $name, $value) use ($cbc, $cur) { $cbc($name, $value, ['currencyID' => $cur]); };

        $cbc('CustomizationID', self::CUSTOMIZATION);
        $cbc('ProfileID', self::PROFILE);
        $cbc('ID', $inv['number']);
        $cbc('IssueDate', $inv['issue_date']);
        if (!$credit) {
            $cbc('DueDate', $inv['due_date']);
        }
        $cbc($credit ? 'CreditNoteTypeCode' : 'InvoiceTypeCode', $credit ? '381' : '380');
        $notes = array_filter([(string)$inv['note'], $credit ? (string)$inv['correction_reason'] : '']);
        foreach ($notes as $n) {
            $cbc('Note', $n);
        }
        $cbc('DocumentCurrencyCode', $cur);
        $cbc('BuyerReference', $inv['order_reference'] !== '' ? $inv['order_reference'] : $inv['number']);   // BT-10 (PEPPOL-EN16931-R003)
        if ($inv['order_reference'] !== '') {
            $w->startElement('cac:OrderReference');
            $cbc('ID', $inv['order_reference']);
            $w->endElement();
        }
        if ($credit && $original) {
            $w->startElement('cac:BillingReference');
            $w->startElement('cac:InvoiceDocumentReference');
            $cbc('ID', $original['number']);
            $cbc('IssueDate', $original['issue_date']);
            $w->endElement();
            $w->endElement();
        }

        // seller (BG-4) and buyer (BG-7)
        $party = function (string $tag, array $p) use ($w, $cbc, $inv) {
            $w->startElement('cac:' . $tag);
            $w->startElement('cac:Party');
            $cbc('EndpointID', $p['dic'], ['schemeID' => self::SCHEME_SK]);
            $w->startElement('cac:PartyName');
            $cbc('Name', $p['name']);
            $w->endElement();
            $w->startElement('cac:PostalAddress');
            $cbc('StreetName', $p['street']);
            $cbc('CityName', $p['city']);
            $cbc('PostalZone', str_replace(' ', '', (string)$p['zip']));
            $w->startElement('cac:Country');
            $cbc('IdentificationCode', strtoupper((string)$p['country'] ?: 'SK'));
            $w->endElement();
            $w->endElement();
            if ($p['icdph'] !== '') {                         // VAT identifier (BT-31 / BT-48)
                $w->startElement('cac:PartyTaxScheme');
                $cbc('CompanyID', $p['icdph']);
                $w->startElement('cac:TaxScheme');
                $cbc('ID', 'VAT');
                $w->endElement();
                $w->endElement();
            } elseif ($tag === 'AccountingSupplierParty' && $p['dic'] !== '') {   // tax registration identifier (BT-32)
                $w->startElement('cac:PartyTaxScheme');
                $cbc('CompanyID', $p['dic']);
                $w->startElement('cac:TaxScheme');
                $cbc('ID', 'TAX');
                $w->endElement();
                $w->endElement();
            }
            $w->startElement('cac:PartyLegalEntity');
            $cbc('RegistrationName', $p['name']);
            $cbc('CompanyID', $p['ico']);
            if ($tag === 'AccountingSupplierParty') {
                $cbc('CompanyLegalForm', $p['registration'] ?? '');
            }
            $w->endElement();
            if (($p['email'] ?? '') !== '' || ($p['phone'] ?? '') !== '') {
                $w->startElement('cac:Contact');
                $cbc('Telephone', $p['phone'] ?? '');
                $cbc('ElectronicMail', $p['email'] ?? '');
                $w->endElement();
            }
            $w->endElement();
            $w->endElement();
        };
        $party('AccountingSupplierParty', ['dic' => (string)$supplier['dic'], 'name' => $supplier['name'], 'street' => $supplier['street'],
            'city' => $supplier['city'], 'zip' => $supplier['zip'], 'country' => $supplier['country'], 'ico' => (string)$supplier['ico'],
            'icdph' => !empty($supplier['vat_payer']) ? (string)$supplier['icdph'] : '', 'registration' => (string)($supplier['registration'] ?? ''),
            'email' => (string)($supplier['email'] ?? ''), 'phone' => (string)($supplier['phone'] ?? '')]);
        // BR-O-02: with category O (seller not a VAT payer) no VAT identifiers at all
        $hasO = in_array('O', array_column($inv['lines'], 'vat_category'), true);
        $party('AccountingCustomerParty', ['dic' => (string)$inv['buyer_dic'], 'name' => $inv['buyer_name'], 'street' => $inv['buyer_street'],
            'city' => $inv['buyer_city'], 'zip' => $inv['buyer_zip'], 'country' => $inv['buyer_country'], 'ico' => (string)$inv['buyer_ico'],
            'icdph' => $hasO ? '' : (string)$inv['buyer_icdph'], 'email' => (string)$inv['buyer_email'], 'phone' => (string)$inv['buyer_phone']]);

        $w->startElement('cac:Delivery');
        $cbc('ActualDeliveryDate', $inv['delivery_date']);
        $w->endElement();

        $means = self::PAYMENT_MEANS[$inv['payment_method']] ?? '1';
        $w->startElement('cac:PaymentMeans');
        $cbc('PaymentMeansCode', $means);
        $cbc('PaymentID', $inv['variable_symbol']);
        if ($means === '58' && $inv['iban'] !== '') {
            $w->startElement('cac:PayeeFinancialAccount');
            $cbc('ID', $inv['iban']);
            $cbc('Name', $inv['bank_account_name']);
            if ($inv['bic'] !== '') {
                $w->startElement('cac:FinancialInstitutionBranch');
                $cbc('ID', $inv['bic']);
                $w->endElement();
            }
            $w->endElement();
        }
        $w->endElement();

        // VAT breakdown (BG-23)
        $taxCategory = function (string $tag, string $cat, $rate, bool $withReason) use ($w, $cbc, $legal) {
            $w->startElement('cac:' . $tag);
            $cbc('ID', $cat);
            if ($cat !== 'O') {
                $cbc('Percent', Calculator::fmt((float)$rate));
            }
            if ($withReason && $cat !== 'S' && $cat !== 'Z') {
                $reason = $cat === 'O' ? (string)($legal['non_vat_payer'] ?? '') : (string)($legal['vat_' . $cat] ?? '');
                $cbc('TaxExemptionReason', $reason !== '' ? $reason : self::defaultReason($cat));
            }
            $w->startElement('cac:TaxScheme');
            $cbc('ID', 'VAT');
            $w->endElement();
            $w->endElement();
        };
        $w->startElement('cac:TaxTotal');
        $money('TaxAmount', $amt($inv['total_vat']));
        foreach ($inv['breakdown'] as $b) {
            $w->startElement('cac:TaxSubtotal');
            $money('TaxableAmount', $amt($b['net']));
            $money('TaxAmount', $amt($b['vat']));
            $taxCategory('TaxCategory', $b['category'], $b['rate'], true);
            $w->endElement();
        }
        $w->endElement();

        $w->startElement('cac:LegalMonetaryTotal');
        $money('LineExtensionAmount', $amt($inv['total_net']));
        $money('TaxExclusiveAmount', $amt($inv['total_net']));
        $money('TaxInclusiveAmount', $amt($inv['total']));
        $money('PayableAmount', $amt($inv['total']));
        $w->endElement();

        foreach ($inv['lines'] as $i => $l) {
            $w->startElement($credit ? 'cac:CreditNoteLine' : 'cac:InvoiceLine');
            $cbc('ID', (string)($i + 1));
            // BR-27: the price is never negative → a discount line (negative price) becomes a negative quantity
            $q = $sign * (float)$l['quantity'] * ((float)$l['unit_price'] < 0 ? -1 : 1);
            $qty = rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.');
            $cbc($credit ? 'CreditedQuantity' : 'InvoicedQuantity', $qty, ['unitCode' => self::unitCode((string)$l['unit'])]);
            $money('LineExtensionAmount', $amt($l['net']));
            $w->startElement('cac:Item');
            $cbc('Name', $l['name']);
            if ((string)$l['sku'] !== '') {
                $w->startElement('cac:SellersItemIdentification');
                $cbc('ID', $l['sku']);
                $w->endElement();
            }
            $taxCategory('ClassifiedTaxCategory', $l['vat_category'], $l['vat_rate'], false);
            $w->endElement();
            $w->startElement('cac:Price');
            $money('PriceAmount', rtrim(rtrim(number_format(abs((float)$l['unit_price']), 4, '.', ''), '0'), '.') ?: '0');
            $w->endElement();
            $w->endElement();
        }
        $w->endElement();
        $w->endDocument();
        return $w->outputMemory();
    }

    public static function unitCode(string $unit): string
    {
        return self::UNITS[mb_strtolower(trim($unit), 'UTF-8')] ?? 'C62';
    }

    private static function defaultReason(string $cat): string
    {
        return ['O' => 'Not subject to VAT', 'E' => 'Exempt from VAT', 'AE' => 'Reverse charge', 'K' => 'Intra-community supply',
                'G' => 'Export outside the EU'][$cat] ?? 'Exempt';
    }
}
