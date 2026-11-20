-- Invoice look closer to the owner's design + everything the old shop invoices had (owner 2026-11-21).

-- name of the bank of an account (printed: "Banka: Fio banka, a.s."; the domestic account number is derived from the IBAN)
ALTER TABLE `{p}company_bank` ADD COLUMN `bank` VARCHAR(100) NOT NULL DEFAULT '' AFTER `bic`;

-- texts of invoices from e-shop orders (editable in Nastavenia → Legislatíva like the other printed texts)
INSERT INTO `{p}legal_text` (code, text, valid_from, note) VALUES
  ('delivery_note', 'Táto faktúra slúži zároveň aj ako dodací list.', '2000-01-01', 'faktúra z objednávky e-shopu (prázdne = netlačí sa)'),
  ('thanks_shop', 'Ďakujeme Vám za nákup na {shop}.', '2000-01-01', 'faktúra z objednávky e-shopu — {shop} = názov e-shopu (prázdne = netlačí sa)');
