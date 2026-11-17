<?php
declare(strict_types=1);

/**
 * R141: country and province/state for companies, customers, vendors and tax codes.
 *
 * Countries are stored by English name (customers and vendors already stored
 * "Canada"); ISO 3166 alpha-2 codes are derived from the name for tax matching.
 * Canada uses its 13 province/territory codes. Elsewhere the province/state is
 * an optional code of up to 10 letters, digits or dashes (for example NY, MH, ENG).
 */

const TEGH_CANADA_PROVINCES = ['AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','QC','SK','YT'];

function tegh_countries(): array
{
    static $list = ['AF'=>'Afghanistan','AL'=>'Albania','DZ'=>'Algeria','AD'=>'Andorra','AO'=>'Angola','AI'=>'Anguilla','AQ'=>'Antarctica','AG'=>'Antigua and Barbuda','AR'=>'Argentina','AM'=>'Armenia','AW'=>'Aruba','AU'=>'Australia','AT'=>'Austria','AZ'=>'Azerbaijan','BS'=>'Bahamas','BH'=>'Bahrain','BD'=>'Bangladesh','BB'=>'Barbados','BY'=>'Belarus','BE'=>'Belgium','BZ'=>'Belize','BJ'=>'Benin','BM'=>'Bermuda','BT'=>'Bhutan','BO'=>'Bolivia','BA'=>'Bosnia and Herzegovina','BW'=>'Botswana','BV'=>'Bouvet Island','BR'=>'Brazil','IO'=>'British Indian Ocean Territory','BN'=>'Brunei','BG'=>'Bulgaria','BF'=>'Burkina Faso','BI'=>'Burundi','KH'=>'Cambodia','CM'=>'Cameroon','CA'=>'Canada','CV'=>'Cape Verde','BQ'=>'Caribbean NL','KY'=>'Cayman Islands','CF'=>'Central African Rep.','TD'=>'Chad','CL'=>'Chile','CN'=>'China','CX'=>'Christmas Island','CC'=>'Cocos (Keeling) Islands','CO'=>'Colombia','KM'=>'Comoros','CD'=>'Congo (Democratic Republic)','CG'=>'Congo (Republic)','CK'=>'Cook Islands','CR'=>'Costa Rica','HR'=>'Croatia','CU'=>'Cuba','CW'=>'Curaçao','CY'=>'Cyprus','CZ'=>'Czechia','CI'=>'Côte d\'Ivoire','DK'=>'Denmark','DJ'=>'Djibouti','DM'=>'Dominica','DO'=>'Dominican Republic','TL'=>'East Timor','EC'=>'Ecuador','EG'=>'Egypt','SV'=>'El Salvador','GQ'=>'Equatorial Guinea','ER'=>'Eritrea','EE'=>'Estonia','SZ'=>'Eswatini','ET'=>'Ethiopia','FK'=>'Falkland Islands','FO'=>'Faroe Islands','FJ'=>'Fiji','FI'=>'Finland','FR'=>'France','GF'=>'French Guiana','PF'=>'French Polynesia','TF'=>'French S. Terr.','GA'=>'Gabon','GM'=>'Gambia','GE'=>'Georgia','DE'=>'Germany','GH'=>'Ghana','GI'=>'Gibraltar','GR'=>'Greece','GL'=>'Greenland','GD'=>'Grenada','GP'=>'Guadeloupe','GU'=>'Guam','GT'=>'Guatemala','GG'=>'Guernsey','GN'=>'Guinea','GW'=>'Guinea-Bissau','GY'=>'Guyana','HT'=>'Haiti','HM'=>'Heard Island and McDonald Islands','HN'=>'Honduras','HK'=>'Hong Kong','HU'=>'Hungary','IS'=>'Iceland','IN'=>'India','ID'=>'Indonesia','IR'=>'Iran','IQ'=>'Iraq','IE'=>'Ireland','IM'=>'Isle of Man','IL'=>'Israel','IT'=>'Italy','JM'=>'Jamaica','JP'=>'Japan','JE'=>'Jersey','JO'=>'Jordan','KZ'=>'Kazakhstan','KE'=>'Kenya','KI'=>'Kiribati','KW'=>'Kuwait','KG'=>'Kyrgyzstan','LA'=>'Laos','LV'=>'Latvia','LB'=>'Lebanon','LS'=>'Lesotho','LR'=>'Liberia','LY'=>'Libya','LI'=>'Liechtenstein','LT'=>'Lithuania','LU'=>'Luxembourg','MO'=>'Macau','MG'=>'Madagascar','MW'=>'Malawi','MY'=>'Malaysia','MV'=>'Maldives','ML'=>'Mali','MT'=>'Malta','MH'=>'Marshall Islands','MQ'=>'Martinique','MR'=>'Mauritania','MU'=>'Mauritius','YT'=>'Mayotte','MX'=>'Mexico','FM'=>'Micronesia','MD'=>'Moldova','MC'=>'Monaco','MN'=>'Mongolia','ME'=>'Montenegro','MS'=>'Montserrat','MA'=>'Morocco','MZ'=>'Mozambique','MM'=>'Myanmar (Burma)','NA'=>'Namibia','NR'=>'Nauru','NP'=>'Nepal','NL'=>'Netherlands','NC'=>'New Caledonia','NZ'=>'New Zealand','NI'=>'Nicaragua','NE'=>'Niger','NG'=>'Nigeria','NU'=>'Niue','NF'=>'Norfolk Island','KP'=>'North Korea','MK'=>'North Macedonia','MP'=>'Northern Mariana Islands','NO'=>'Norway','OM'=>'Oman','PK'=>'Pakistan','PW'=>'Palau','PS'=>'Palestine','PA'=>'Panama','PG'=>'Papua New Guinea','PY'=>'Paraguay','PE'=>'Peru','PH'=>'Philippines','PN'=>'Pitcairn','PL'=>'Poland','PT'=>'Portugal','PR'=>'Puerto Rico','QA'=>'Qatar','RO'=>'Romania','RU'=>'Russia','RW'=>'Rwanda','RE'=>'Réunion','AS'=>'Samoa (American)','WS'=>'Samoa (western)','SM'=>'San Marino','ST'=>'Sao Tome and Principe','SA'=>'Saudi Arabia','SN'=>'Senegal','RS'=>'Serbia','SC'=>'Seychelles','SL'=>'Sierra Leone','SG'=>'Singapore','SK'=>'Slovakia','SI'=>'Slovenia','SB'=>'Solomon Islands','SO'=>'Somalia','ZA'=>'South Africa','GS'=>'South Georgia and the South Sandwich Islands','KR'=>'South Korea','SS'=>'South Sudan','ES'=>'Spain','LK'=>'Sri Lanka','BL'=>'St Barthelemy','SH'=>'St Helena','KN'=>'St Kitts and Nevis','LC'=>'St Lucia','SX'=>'St Maarten (Dutch)','MF'=>'St Martin (French)','PM'=>'St Pierre and Miquelon','VC'=>'St Vincent','SD'=>'Sudan','SR'=>'Suriname','SJ'=>'Svalbard and Jan Mayen','SE'=>'Sweden','CH'=>'Switzerland','SY'=>'Syria','TW'=>'Taiwan','TJ'=>'Tajikistan','TZ'=>'Tanzania','TH'=>'Thailand','TG'=>'Togo','TK'=>'Tokelau','TO'=>'Tonga','TT'=>'Trinidad and Tobago','TN'=>'Tunisia','TR'=>'Turkey','TM'=>'Turkmenistan','TC'=>'Turks and Caicos Is','TV'=>'Tuvalu','UM'=>'US minor outlying islands','UG'=>'Uganda','UA'=>'Ukraine','AE'=>'United Arab Emirates','GB'=>'United Kingdom','US'=>'United States','UY'=>'Uruguay','UZ'=>'Uzbekistan','VU'=>'Vanuatu','VA'=>'Vatican City','VE'=>'Venezuela','VN'=>'Vietnam','VG'=>'Virgin Islands (UK)','VI'=>'Virgin Islands (US)','WF'=>'Wallis and Futuna','EH'=>'Western Sahara','YE'=>'Yemen','ZM'=>'Zambia','ZW'=>'Zimbabwe','AX'=>'Åland Islands'];
    return $list;
}

/** ISO alpha-2 code for a country name or code, or null when unknown. */
function tegh_country_code(?string $value): ?string
{
    $v = trim((string)$value);
    if ($v === '') return null;
    $u = strtoupper($v);
    $aliases = ['CANADA' => 'CA', 'CAN' => 'CA', 'USA' => 'US', 'U.S.A.' => 'US', 'U.S.' => 'US', 'UNITED STATES OF AMERICA' => 'US', 'UK' => 'GB', 'U.K.' => 'GB', 'GREAT BRITAIN' => 'GB', 'BRITAIN' => 'GB', 'ENGLAND' => 'GB', 'SCOTLAND' => 'GB', 'WALES' => 'GB', 'NORTHERN IRELAND' => 'GB', 'UAE' => 'AE'];
    if (isset($aliases[$u])) return $aliases[$u];
    $list = tegh_countries();
    if (strlen($u) === 2 && isset($list[$u])) return $u;
    foreach ($list as $code => $name) if (strtoupper($name) === $u) return $code;
    return null;
}

function tegh_country_name(string $code): string
{
    return tegh_countries()[strtoupper($code)] ?? $code;
}

/**
 * Validate a country + province/state pair.
 * Returns ['country' => English name, 'countryCode' => ISO2, 'province' => ?string].
 */
function tegh_location(mixed $country, mixed $province, bool $requireCanadianProvince = true, string $label = 'Province or state', ?string $defaultCountry = 'Canada'): array
{
    $raw = trim((string)($country ?? ''));
    if ($raw === '' && $defaultCountry !== null) $raw = $defaultCountry;
    $code = tegh_country_code($raw);
    if ($code === null) fail('Choose a country from the list.', 422, 'country_invalid');
    $prov = strtoupper(trim((string)($province ?? '')));
    if ($code === 'CA') {
        if ($prov === '' && !$requireCanadianProvince) return ['country' => 'Canada', 'countryCode' => 'CA', 'province' => null];
        if (!in_array($prov, TEGH_CANADA_PROVINCES, true)) fail('Choose a Canadian province or territory.', 422, 'province_invalid');
        return ['country' => 'Canada', 'countryCode' => 'CA', 'province' => $prov];
    }
    if ($prov !== '' && !preg_match('/^[A-Z0-9][A-Z0-9-]{0,9}$/', $prov)) fail($label . ': use a short code of up to 10 letters or digits (for example NY or MH).', 422, 'province_invalid');
    return ['country' => tegh_country_name($code), 'countryCode' => $code, 'province' => $prov === '' ? null : $prov];
}

/** Tax-code regions to try, most specific first: ON; or US-NY then US. */
function tegh_tax_region_candidates(?string $country, ?string $province): array
{
    $code = tegh_country_code($country) ?? 'CA';
    $prov = strtoupper(trim((string)$province));
    if ($code === 'CA') return $prov !== '' ? [$prov] : [];
    return $prov !== '' ? [$code . '-' . $prov, $code] : [$code];
}

/** Columns for R141, created on first use (outside any transaction). Needs ALTER privilege. */
function tegh_regions_ready(): bool
{
    static $ready = null;
    if ($ready === true) return true;
    $probe = db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND ((table_name='companies' AND column_name='country') OR (table_name IN ('companies','customers','vendors') AND column_name='province' AND character_maximum_length>=10))")->fetchColumn();
    if ((int)$probe === 4) return $ready = true;
    if (db()->inTransaction()) return $ready = false;
    if (!schema_column_exists('companies', 'country')) db()->exec("ALTER TABLE companies ADD COLUMN country VARCHAR(80) NOT NULL DEFAULT 'Canada' AFTER province");
    foreach (['companies' => "VARCHAR(10) NOT NULL DEFAULT 'ON'", 'customers' => 'VARCHAR(10) NULL DEFAULT NULL', 'vendors' => 'VARCHAR(10) NULL DEFAULT NULL'] as $table => $definition) {
        $len = db()->prepare("SELECT character_maximum_length FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name='province'");
        $len->execute([$table]);
        $max = $len->fetchColumn();
        if ($max !== false && (int)$max < 10) db()->exec("ALTER TABLE `$table` MODIFY province $definition");
    }
    return $ready = true;
}
