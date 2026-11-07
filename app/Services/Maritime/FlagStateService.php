<?php

namespace App\Services\Maritime;

use App\Models\Vessel;

class FlagStateService
{
    /**
     * Complete ITU Maritime Identification Digits (MID) Table
     * ITU Radio Regulations, Appendix 42 (Table of Allocation of Maritime Identification Digits)
     */
    protected static array $midTable = [
        // Africa
        '601' => ['name' => 'South Africa', 'iso2' => 'ZA', 'iso3' => 'ZAF'],
        '603' => ['name' => 'Angola', 'iso2' => 'AO', 'iso3' => 'AGO'],
        '605' => ['name' => 'Algeria', 'iso2' => 'DZ', 'iso3' => 'DZA'],
        '607' => ['name' => 'Saint Paul and Amsterdam Islands', 'iso2' => 'TF', 'iso3' => 'ATF'],
        '609' => ['name' => 'Burundi', 'iso2' => 'BI', 'iso3' => 'BDI'],
        '610' => ['name' => 'Benin', 'iso2' => 'BJ', 'iso3' => 'BEN'],
        '611' => ['name' => 'Botswana', 'iso2' => 'BW', 'iso3' => 'BWA'],
        '612' => ['name' => 'Central African Republic', 'iso2' => 'CF', 'iso3' => 'CAF'],
        '613' => ['name' => 'Cameroon', 'iso2' => 'CM', 'iso3' => 'CMR'],
        '615' => ['name' => 'Congo', 'iso2' => 'CG', 'iso3' => 'COG'],
        '616' => ['name' => 'Comoros', 'iso2' => 'KM', 'iso3' => 'COM'],
        '617' => ['name' => 'Cabo Verde', 'iso2' => 'CV', 'iso3' => 'CPV'],
        '618' => ['name' => 'Crozet Archipelago', 'iso2' => 'TF', 'iso3' => 'ATF'],
        '619' => ['name' => 'Côte d\'Ivoire', 'iso2' => 'CI', 'iso3' => 'CIV'],
        '621' => ['name' => 'Djibouti', 'iso2' => 'DJ', 'iso3' => 'DJI'],
        '622' => ['name' => 'Egypt', 'iso2' => 'EG', 'iso3' => 'EGY'],
        '624' => ['name' => 'Ethiopia', 'iso2' => 'ET', 'iso3' => 'ETH'],
        '625' => ['name' => 'Eritrea', 'iso2' => 'ER', 'iso3' => 'ERI'],
        '626' => ['name' => 'Gabon', 'iso2' => 'GA', 'iso3' => 'GAB'],
        '627' => ['name' => 'Ghana', 'iso2' => 'GH', 'iso3' => 'GHA'],
        '629' => ['name' => 'Gambia', 'iso2' => 'GM', 'iso3' => 'GMB'],
        '630' => ['name' => 'Guinea-Bissau', 'iso2' => 'GW', 'iso3' => 'GNB'],
        '631' => ['name' => 'Equatorial Guinea', 'iso2' => 'GQ', 'iso3' => 'GNQ'],
        '632' => ['name' => 'Guinea', 'iso2' => 'GN', 'iso3' => 'GIN'],
        '633' => ['name' => 'Burkina Faso', 'iso2' => 'BF', 'iso3' => 'BFA'],
        '634' => ['name' => 'Kenya', 'iso2' => 'KE', 'iso3' => 'KEN'],
        '635' => ['name' => 'Kerguelen Islands', 'iso2' => 'TF', 'iso3' => 'ATF'],
        '636' => ['name' => 'Liberia', 'iso2' => 'LR', 'iso3' => 'LBR'],
        '637' => ['name' => 'Liberia', 'iso2' => 'LR', 'iso3' => 'LBR'],
        '642' => ['name' => 'Madagascar', 'iso2' => 'MG', 'iso3' => 'MDG'],
        '644' => ['name' => 'Mauritius', 'iso2' => 'MU', 'iso3' => 'MUS'],
        '645' => ['name' => 'Mauritania', 'iso2' => 'MR', 'iso3' => 'MRT'],
        '647' => ['name' => 'Morocco', 'iso2' => 'MA', 'iso3' => 'MAR'],
        '649' => ['name' => 'Mozambique', 'iso2' => 'MZ', 'iso3' => 'MOZ'],
        '650' => ['name' => 'Namibia', 'iso2' => 'NA', 'iso3' => 'NAM'],
        '654' => ['name' => 'Nigeria', 'iso2' => 'NG', 'iso3' => 'NGA'],
        '655' => ['name' => 'Nigeria', 'iso2' => 'NG', 'iso3' => 'NGA'],
        '656' => ['name' => 'Seychelles', 'iso2' => 'SC', 'iso3' => 'SYC'],
        '657' => ['name' => 'Senegal', 'iso2' => 'SN', 'iso3' => 'SEN'],
        '659' => ['name' => 'Somalia', 'iso2' => 'SO', 'iso3' => 'SOM'],
        '660' => ['name' => 'Sierra Leone', 'iso2' => 'SL', 'iso3' => 'SLE'],
        '661' => ['name' => 'Sao Tome and Principe', 'iso2' => 'ST', 'iso3' => 'STP'],
        '662' => ['name' => 'Sudan', 'iso2' => 'SD', 'iso3' => 'SDN'],
        '663' => ['name' => 'Senegal', 'iso2' => 'SN', 'iso3' => 'SEN'],
        '664' => ['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA'],
        '667' => ['name' => 'Tunisia', 'iso2' => 'TN', 'iso3' => 'TUN'],
        '670' => ['name' => 'Tanzania (Zanzibar)', 'iso2' => 'TZ', 'iso3' => 'TZA'],
        '671' => ['name' => 'Togo', 'iso2' => 'TG', 'iso3' => 'TGO'],
        '672' => ['name' => 'Tunisia', 'iso2' => 'TN', 'iso3' => 'TUN'],

        // Asia
        '412' => ['name' => 'China', 'iso2' => 'CN', 'iso3' => 'CHN'],
        '413' => ['name' => 'China', 'iso2' => 'CN', 'iso3' => 'CHN'],
        '414' => ['name' => 'China', 'iso2' => 'CN', 'iso3' => 'CHN'],
        '416' => ['name' => 'Taiwan', 'iso2' => 'TW', 'iso3' => 'TWN'],
        '417' => ['name' => 'Sri Lanka', 'iso2' => 'LK', 'iso3' => 'LKA'],
        '419' => ['name' => 'India', 'iso2' => 'IN', 'iso3' => 'IND'],
        '422' => ['name' => 'Iran', 'iso2' => 'IR', 'iso3' => 'IRN'],
        '424' => ['name' => 'Iraq', 'iso2' => 'IQ', 'iso3' => 'IRQ'],
        '425' => ['name' => 'Israel', 'iso2' => 'IL', 'iso3' => 'ISR'],
        '428' => ['name' => 'Israel', 'iso2' => 'IL', 'iso3' => 'ISR'],
        '431' => ['name' => 'Japan', 'iso2' => 'JP', 'iso3' => 'JPN'],
        '432' => ['name' => 'Japan', 'iso2' => 'JP', 'iso3' => 'JPN'],
        '440' => ['name' => 'Korea (Republic of)', 'iso2' => 'KR', 'iso3' => 'KOR'],
        '441' => ['name' => 'Korea (Republic of)', 'iso2' => 'KR', 'iso3' => 'KOR'],
        '445' => ['name' => 'Democratic People\'s Republic of Korea', 'iso2' => 'KP', 'iso3' => 'PRK'],
        '447' => ['name' => 'Kuwait', 'iso2' => 'KW', 'iso3' => 'KWT'],
        '450' => ['name' => 'Lebanon', 'iso2' => 'LB', 'iso3' => 'LBN'],
        '453' => ['name' => 'Macao', 'iso2' => 'MO', 'iso3' => 'MAC'],
        '455' => ['name' => 'Maldives', 'iso2' => 'MV', 'iso3' => 'MDV'],
        '457' => ['name' => 'Mongolia', 'iso2' => 'MN', 'iso3' => 'MNG'],
        '461' => ['name' => 'Oman', 'iso2' => 'OM', 'iso3' => 'OMN'],
        '463' => ['name' => 'Pakistan', 'iso2' => 'PK', 'iso3' => 'PAK'],
        '466' => ['name' => 'Qatar', 'iso2' => 'QA', 'iso3' => 'QAT'],
        '470' => ['name' => 'Saudi Arabia', 'iso2' => 'SA', 'iso3' => 'SAU'],
        '471' => ['name' => 'Saudi Arabia', 'iso2' => 'SA', 'iso3' => 'SAU'],
        '473' => ['name' => 'Yemen', 'iso2' => 'YE', 'iso3' => 'YEM'],
        '475' => ['name' => 'Yemen', 'iso2' => 'YE', 'iso3' => 'YEM'],
        '477' => ['name' => 'Hong Kong', 'iso2' => 'HK', 'iso3' => 'HKG'],
        '501' => ['name' => 'Antarctica', 'iso2' => 'AQ', 'iso3' => 'ATA'],
        '503' => ['name' => 'Australia', 'iso2' => 'AU', 'iso3' => 'AUS'],
        '506' => ['name' => 'Myanmar', 'iso2' => 'MM', 'iso3' => 'MMR'],
        '508' => ['name' => 'Brunei Darussalam', 'iso2' => 'BN', 'iso3' => 'BRN'],
        '511' => ['name' => 'Palau', 'iso2' => 'PW', 'iso3' => 'PLW'],
        '512' => ['name' => 'New Zealand', 'iso2' => 'NZ', 'iso3' => 'NZL'],
        '514' => ['name' => 'Cambodia', 'iso2' => 'KH', 'iso3' => 'KHM'],
        '515' => ['name' => 'Cambodia', 'iso2' => 'KH', 'iso3' => 'KHM'],
        '516' => ['name' => 'Christmas Island', 'iso2' => 'CX', 'iso3' => 'CXR'],
        '518' => ['name' => 'Cook Islands', 'iso2' => 'CK', 'iso3' => 'COK'],
        '520' => ['name' => 'Fiji', 'iso2' => 'FJ', 'iso3' => 'FJI'],
        '523' => ['name' => 'Cocos (Keeling) Islands', 'iso2' => 'CC', 'iso3' => 'CCK'],
        '525' => ['name' => 'Indonesia', 'iso2' => 'ID', 'iso3' => 'IDN'],
        '529' => ['name' => 'Kiribati', 'iso2' => 'KI', 'iso3' => 'KIR'],
        '531' => ['name' => 'Lao People\'s Democratic Republic', 'iso2' => 'LA', 'iso3' => 'LAO'],
        '533' => ['name' => 'Malaysia', 'iso2' => 'MY', 'iso3' => 'MYS'],
        '536' => ['name' => 'Northern Mariana Islands', 'iso2' => 'MP', 'iso3' => 'MNP'],
        '538' => ['name' => 'Marshall Islands', 'iso2' => 'MH', 'iso3' => 'MHL'],
        '540' => ['name' => 'New Caledonia', 'iso2' => 'NC', 'iso3' => 'NCL'],
        '542' => ['name' => 'Niue', 'iso2' => 'NU', 'iso3' => 'NIU'],
        '544' => ['name' => 'Nauru', 'iso2' => 'NR', 'iso3' => 'NRU'],
        '546' => ['name' => 'French Polynesia', 'iso2' => 'PF', 'iso3' => 'PYF'],
        '548' => ['name' => 'Philippines', 'iso2' => 'PH', 'iso3' => 'PHL'],
        '553' => ['name' => 'Papua New Guinea', 'iso2' => 'PG', 'iso3' => 'PNG'],
        '555' => ['name' => 'Pitcairn Island', 'iso2' => 'PN', 'iso3' => 'PCN'],
        '557' => ['name' => 'Solomon Islands', 'iso2' => 'SB', 'iso3' => 'SLB'],
        '559' => ['name' => 'American Samoa', 'iso2' => 'AS', 'iso3' => 'ASM'],
        '561' => ['name' => 'Samoa', 'iso2' => 'WS', 'iso3' => 'WSM'],
        '563' => ['name' => 'Singapore', 'iso2' => 'SG', 'iso3' => 'SGP'],
        '564' => ['name' => 'Singapore', 'iso2' => 'SG', 'iso3' => 'SGP'],
        '565' => ['name' => 'Singapore', 'iso2' => 'SG', 'iso3' => 'SGP'],
        '566' => ['name' => 'Singapore', 'iso2' => 'SG', 'iso3' => 'SGP'],
        '567' => ['name' => 'Thailand', 'iso2' => 'TH', 'iso3' => 'THA'],
        '570' => ['name' => 'Tonga', 'iso2' => 'TO', 'iso3' => 'TON'],
        '572' => ['name' => 'Tuvalu', 'iso2' => 'TV', 'iso3' => 'TUV'],
        '574' => ['name' => 'Vietnam', 'iso2' => 'VN', 'iso3' => 'VNM'],
        '576' => ['name' => 'Vanuatu', 'iso2' => 'VU', 'iso3' => 'VUT'],
        '577' => ['name' => 'Vanuatu', 'iso2' => 'VU', 'iso3' => 'VUT'],
        '578' => ['name' => 'Wallis and Futuna Islands', 'iso2' => 'WF', 'iso3' => 'WLF'],

        // Europe
        '201' => ['name' => 'Albania', 'iso2' => 'AL', 'iso3' => 'ALB'],
        '202' => ['name' => 'Andorra', 'iso2' => 'AD', 'iso3' => 'AND'],
        '203' => ['name' => 'Austria', 'iso2' => 'AT', 'iso3' => 'AUT'],
        '204' => ['name' => 'Azores', 'iso2' => 'PT', 'iso3' => 'PRT'],
        '205' => ['name' => 'Belgium', 'iso2' => 'BE', 'iso3' => 'BEL'],
        '206' => ['name' => 'Belarus', 'iso2' => 'BY', 'iso3' => 'BLR'],
        '207' => ['name' => 'Bulgaria', 'iso2' => 'BG', 'iso3' => 'BGR'],
        '208' => ['name' => 'Vatican City State', 'iso2' => 'VA', 'iso3' => 'VAT'],
        '209' => ['name' => 'Cyprus', 'iso2' => 'CY', 'iso3' => 'CYP'],
        '210' => ['name' => 'Cyprus', 'iso2' => 'CY', 'iso3' => 'CYP'],
        '211' => ['name' => 'Germany', 'iso2' => 'DE', 'iso3' => 'DEU'],
        '212' => ['name' => 'Cyprus', 'iso2' => 'CY', 'iso3' => 'CYP'],
        '213' => ['name' => 'Georgia', 'iso2' => 'GE', 'iso3' => 'GEO'],
        '214' => ['name' => 'Moldova', 'iso2' => 'MD', 'iso3' => 'MDA'],
        '215' => ['name' => 'Malta', 'iso2' => 'MT', 'iso3' => 'MLT'],
        '216' => ['name' => 'Armenia', 'iso2' => 'AM', 'iso3' => 'ARM'],
        '218' => ['name' => 'Germany', 'iso2' => 'DE', 'iso3' => 'DEU'],
        '219' => ['name' => 'Denmark', 'iso2' => 'DK', 'iso3' => 'DNK'],
        '220' => ['name' => 'Denmark', 'iso2' => 'DK', 'iso3' => 'DNK'],
        '224' => ['name' => 'Spain', 'iso2' => 'ES', 'iso3' => 'ESP'],
        '225' => ['name' => 'Spain', 'iso2' => 'ES', 'iso3' => 'ESP'],
        '226' => ['name' => 'France', 'iso2' => 'FR', 'iso3' => 'FRA'],
        '227' => ['name' => 'France', 'iso2' => 'FR', 'iso3' => 'FRA'],
        '228' => ['name' => 'France', 'iso2' => 'FR', 'iso3' => 'FRA'],
        '229' => ['name' => 'Malta', 'iso2' => 'MT', 'iso3' => 'MLT'],
        '230' => ['name' => 'Finland', 'iso2' => 'FI', 'iso3' => 'FIN'],
        '231' => ['name' => 'Faroe Islands', 'iso2' => 'FO', 'iso3' => 'FRO'],
        '232' => ['name' => 'United Kingdom', 'iso2' => 'GB', 'iso3' => 'GBR'],
        '233' => ['name' => 'United Kingdom', 'iso2' => 'GB', 'iso3' => 'GBR'],
        '234' => ['name' => 'United Kingdom', 'iso2' => 'GB', 'iso3' => 'GBR'],
        '235' => ['name' => 'United Kingdom', 'iso2' => 'GB', 'iso3' => 'GBR'],
        '236' => ['name' => 'Gibraltar', 'iso2' => 'GI', 'iso3' => 'GIB'],
        '237' => ['name' => 'Greece', 'iso2' => 'GR', 'iso3' => 'GRC'],
        '238' => ['name' => 'Croatia', 'iso2' => 'HR', 'iso3' => 'HRV'],
        '239' => ['name' => 'Greece', 'iso2' => 'GR', 'iso3' => 'GRC'],
        '240' => ['name' => 'Greece', 'iso2' => 'GR', 'iso3' => 'GRC'],
        '241' => ['name' => 'Greece', 'iso2' => 'GR', 'iso3' => 'GRC'],
        '242' => ['name' => 'Morocco', 'iso2' => 'MA', 'iso3' => 'MAR'],
        '244' => ['name' => 'Netherlands', 'iso2' => 'NL', 'iso3' => 'NLD'],
        '245' => ['name' => 'Netherlands', 'iso2' => 'NL', 'iso3' => 'NLD'],
        '246' => ['name' => 'Netherlands', 'iso2' => 'NL', 'iso3' => 'NLD'],
        '247' => ['name' => 'Italy', 'iso2' => 'IT', 'iso3' => 'ITA'],
        '248' => ['name' => 'Malta', 'iso2' => 'MT', 'iso3' => 'MLT'],
        '249' => ['name' => 'Malta', 'iso2' => 'MT', 'iso3' => 'MLT'],
        '250' => ['name' => 'Ireland', 'iso2' => 'IE', 'iso3' => 'IRL'],
        '251' => ['name' => 'Iceland', 'iso2' => 'IS', 'iso3' => 'ISL'],
        '252' => ['name' => 'Liechtenstein', 'iso2' => 'LI', 'iso3' => 'LIE'],
        '253' => ['name' => 'Luxembourg', 'iso2' => 'LU', 'iso3' => 'LUX'],
        '254' => ['name' => 'Monaco', 'iso2' => 'MC', 'iso3' => 'MCO'],
        '255' => ['name' => 'Madeira', 'iso2' => 'PT', 'iso3' => 'PRT'],
        '256' => ['name' => 'Malta', 'iso2' => 'MT', 'iso3' => 'MLT'],
        '257' => ['name' => 'Norway', 'iso2' => 'NO', 'iso3' => 'NOR'],
        '258' => ['name' => 'Norway', 'iso2' => 'NO', 'iso3' => 'NOR'],
        '259' => ['name' => 'Norway', 'iso2' => 'NO', 'iso3' => 'NOR'],
        '261' => ['name' => 'Poland', 'iso2' => 'PL', 'iso3' => 'POL'],
        '262' => ['name' => 'Montenegro', 'iso2' => 'ME', 'iso3' => 'MNE'],
        '263' => ['name' => 'Portugal', 'iso2' => 'PT', 'iso3' => 'PRT'],
        '264' => ['name' => 'Romania', 'iso2' => 'RO', 'iso3' => 'ROU'],
        '265' => ['name' => 'Sweden', 'iso2' => 'SE', 'iso3' => 'SWE'],
        '266' => ['name' => 'Sweden', 'iso2' => 'SE', 'iso3' => 'SWE'],
        '267' => ['name' => 'Slovak Republic', 'iso2' => 'SK', 'iso3' => 'SVK'],
        '268' => ['name' => 'San Marino', 'iso2' => 'SM', 'iso3' => 'SMR'],
        '269' => ['name' => 'Switzerland', 'iso2' => 'CH', 'iso3' => 'CHE'],
        '270' => ['name' => 'Czech Republic', 'iso2' => 'CZ', 'iso3' => 'CZE'],
        '271' => ['name' => 'Turkey', 'iso2' => 'TR', 'iso3' => 'TUR'],
        '272' => ['name' => 'Ukraine', 'iso2' => 'UA', 'iso3' => 'UKR'],
        '273' => ['name' => 'Russian Federation', 'iso2' => 'RU', 'iso3' => 'RUS'],
        '274' => ['name' => 'North Macedonia', 'iso2' => 'MK', 'iso3' => 'MKD'],
        '275' => ['name' => 'Latvia', 'iso2' => 'LV', 'iso3' => 'LVA'],
        '276' => ['name' => 'Estonia', 'iso2' => 'EE', 'iso3' => 'EST'],
        '277' => ['name' => 'Lithuania', 'iso2' => 'LT', 'iso3' => 'LTU'],
        '278' => ['name' => 'Slovenia', 'iso2' => 'SI', 'iso3' => 'SVN'],
        '279' => ['name' => 'Serbia', 'iso2' => 'RS', 'iso3' => 'SRB'],

        // Americas
        '301' => ['name' => 'Anguilla', 'iso2' => 'AI', 'iso3' => 'AIA'],
        '303' => ['name' => 'Alaska', 'iso2' => 'US', 'iso3' => 'USA'],
        '304' => ['name' => 'Antigua and Barbuda', 'iso2' => 'AG', 'iso3' => 'ATG'],
        '305' => ['name' => 'Antigua and Barbuda', 'iso2' => 'AG', 'iso3' => 'ATG'],
        '306' => ['name' => 'Curacao', 'iso2' => 'CW', 'iso3' => 'CUW'],
        '307' => ['name' => 'Aruba', 'iso2' => 'AW', 'iso3' => 'ABW'],
        '308' => ['name' => 'Bahamas', 'iso2' => 'BS', 'iso3' => 'BHS'],
        '309' => ['name' => 'Bahamas', 'iso2' => 'BS', 'iso3' => 'BHS'],
        '310' => ['name' => 'Bermuda', 'iso2' => 'BM', 'iso3' => 'BMU'],
        '311' => ['name' => 'Bahamas', 'iso2' => 'BS', 'iso3' => 'BHS'],
        '312' => ['name' => 'Belize', 'iso2' => 'BZ', 'iso3' => 'BLZ'],
        '314' => ['name' => 'Barbados', 'iso2' => 'BB', 'iso3' => 'BRB'],
        '316' => ['name' => 'Canada', 'iso2' => 'CA', 'iso3' => 'CAN'],
        '319' => ['name' => 'Cayman Islands', 'iso2' => 'KY', 'iso3' => 'CYM'],
        '321' => ['name' => 'Costa Rica', 'iso2' => 'CR', 'iso3' => 'CRI'],
        '323' => ['name' => 'Cuba', 'iso2' => 'CU', 'iso3' => 'CUB'],
        '325' => ['name' => 'Dominica', 'iso2' => 'DM', 'iso3' => 'DMA'],
        '327' => ['name' => 'Dominican Republic', 'iso2' => 'DO', 'iso3' => 'DOM'],
        '329' => ['name' => 'Guadeloupe', 'iso2' => 'GP', 'iso3' => 'GLP'],
        '330' => ['name' => 'Grenada', 'iso2' => 'GD', 'iso3' => 'GRD'],
        '331' => ['name' => 'Greenland', 'iso2' => 'GL', 'iso3' => 'GRL'],
        '332' => ['name' => 'Guatemala', 'iso2' => 'GT', 'iso3' => 'GTM'],
        '334' => ['name' => 'Honduras', 'iso2' => 'HN', 'iso3' => 'HND'],
        '336' => ['name' => 'Haiti', 'iso2' => 'HT', 'iso3' => 'HTI'],
        '338' => ['name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA'],
        '339' => ['name' => 'Jamaica', 'iso2' => 'JM', 'iso3' => 'JAM'],
        '341' => ['name' => 'Saint Kitts and Nevis', 'iso2' => 'KN', 'iso3' => 'KNA'],
        '343' => ['name' => 'Saint Lucia', 'iso2' => 'LC', 'iso3' => 'LCA'],
        '345' => ['name' => 'Mexico', 'iso2' => 'MX', 'iso3' => 'MEX'],
        '347' => ['name' => 'Martinique', 'iso2' => 'MQ', 'iso3' => 'MTQ'],
        '348' => ['name' => 'Montserrat', 'iso2' => 'MS', 'iso3' => 'MSR'],
        '350' => ['name' => 'Nicaragua', 'iso2' => 'NI', 'iso3' => 'NIC'],
        '351' => ['name' => 'Panama', 'iso2' => 'PA', 'iso3' => 'PAN'],
        '352' => ['name' => 'Panama', 'iso2' => 'PA', 'iso3' => 'PAN'],
        '353' => ['name' => 'Panama', 'iso2' => 'PA', 'iso3' => 'PAN'],
        '354' => ['name' => 'Panama', 'iso2' => 'PA', 'iso3' => 'PAN'],
        '355' => ['name' => 'Panama', 'iso2' => 'PA', 'iso3' => 'PAN'],
        '356' => ['name' => 'Panama', 'iso2' => 'PA', 'iso3' => 'PAN'],
        '357' => ['name' => 'Panama', 'iso2' => 'PA', 'iso3' => 'PAN'],
        '358' => ['name' => 'Puerto Rico', 'iso2' => 'PR', 'iso3' => 'PRI'],
        '359' => ['name' => 'El Salvador', 'iso2' => 'SV', 'iso3' => 'SLV'],
        '361' => ['name' => 'Saint Pierre and Miquelon', 'iso2' => 'PM', 'iso3' => 'SPM'],
        '362' => ['name' => 'Trinidad and Tobago', 'iso2' => 'TT', 'iso3' => 'TTO'],
        '364' => ['name' => 'Turks and Caicos Islands', 'iso2' => 'TC', 'iso3' => 'TCA'],
        '366' => ['name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA'],
        '367' => ['name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA'],
        '368' => ['name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA'],
        '369' => ['name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA'],
        '370' => ['name' => 'Panama', 'iso2' => 'PA', 'iso3' => 'PAN'],
        '371' => ['name' => 'Panama', 'iso2' => 'PA', 'iso3' => 'PAN'],
        '372' => ['name' => 'Panama', 'iso2' => 'PA', 'iso3' => 'PAN'],
        '373' => ['name' => 'Panama', 'iso2' => 'PA', 'iso3' => 'PAN'],
        '374' => ['name' => 'Panama', 'iso2' => 'PA', 'iso3' => 'PAN'],
        '375' => ['name' => 'Saint Vincent and the Grenadines', 'iso2' => 'VC', 'iso3' => 'VCT'],
        '376' => ['name' => 'Saint Vincent and the Grenadines', 'iso2' => 'VC', 'iso3' => 'VCT'],
        '377' => ['name' => 'Saint Vincent and the Grenadines', 'iso2' => 'VC', 'iso3' => 'VCT'],
        '378' => ['name' => 'British Virgin Islands', 'iso2' => 'VG', 'iso3' => 'VGB'],
        '379' => ['name' => 'United States Virgin Islands', 'iso2' => 'VI', 'iso3' => 'VIR'],
        '701' => ['name' => 'Argentina', 'iso2' => 'AR', 'iso3' => 'ARG'],
        '710' => ['name' => 'Brazil', 'iso2' => 'BR', 'iso3' => 'BRA'],
        '720' => ['name' => 'Bolivia', 'iso2' => 'BO', 'iso3' => 'BOL'],
        '725' => ['name' => 'Chile', 'iso2' => 'CL', 'iso3' => 'CHL'],
        '730' => ['name' => 'Colombia', 'iso2' => 'CO', 'iso3' => 'COL'],
        '735' => ['name' => 'Ecuador', 'iso2' => 'EC', 'iso3' => 'ECU'],
        '740' => ['name' => 'Falkland Islands (Malvinas)', 'iso2' => 'FK', 'iso3' => 'FLK'],
        '745' => ['name' => 'Guiana (French Department of)', 'iso2' => 'GF', 'iso3' => 'GUF'],
        '750' => ['name' => 'Guyana', 'iso2' => 'GY', 'iso3' => 'GUY'],
        '755' => ['name' => 'Paraguay', 'iso2' => 'PY', 'iso3' => 'PRY'],
        '760' => ['name' => 'Peru', 'iso2' => 'PE', 'iso3' => 'PER'],
        '765' => ['name' => 'Suriname', 'iso2' => 'SR', 'iso3' => 'SUR'],
        '770' => ['name' => 'Uruguay', 'iso2' => 'UY', 'iso3' => 'URY'],
        '775' => ['name' => 'Venezuela', 'iso2' => 'VE', 'iso3' => 'VEN'],
    ];

    /**
     * ISO 3166-1 alpha-2 to Country Name Table
     */
    protected static array $iso2Table = [
        'ID' => 'Indonesia',
        'CN' => 'China',
        'PA' => 'Panama',
        'LR' => 'Liberia',
        'MH' => 'Marshall Islands',
        'ES' => 'Spain',
        'JP' => 'Japan',
        'KR' => 'Korea (Republic of)',
        'TW' => 'Taiwan',
        'VN' => 'Vietnam',
        'TH' => 'Thailand',
        'MY' => 'Malaysia',
        'PH' => 'Philippines',
        'SG' => 'Singapore',
        'RU' => 'Russian Federation',
        'US' => 'United States',
        'AU' => 'Australia',
        'NZ' => 'New Zealand',
        'GB' => 'United Kingdom',
        'NO' => 'Norway',
        'DK' => 'Denmark',
        'FR' => 'France',
        'IT' => 'Italy',
        'GR' => 'Greece',
        'NL' => 'Netherlands',
        'DE' => 'Germany',
        'IE' => 'Ireland',
        'PT' => 'Portugal',
        'BZ' => 'Belize',
        'BS' => 'Bahamas',
        'BM' => 'Bermuda',
        'KY' => 'Cayman Islands',
        'CY' => 'Cyprus',
        'MT' => 'Malta',
        'VU' => 'Vanuatu',
        'KH' => 'Cambodia',
        'IN' => 'India',
        'BR' => 'Brazil',
        'AR' => 'Argentina',
        'CL' => 'Chile',
        'EC' => 'Ecuador',
        'PE' => 'Peru',
        'ZA' => 'South Africa',
        'CA' => 'Canada',
        'MX' => 'Mexico',
        'IS' => 'Iceland',
        'LT' => 'Lithuania',
        'LV' => 'Latvia',
        'PL' => 'Poland',
        'SC' => 'Seychelles',
        'MU' => 'Mauritius',
    ];

    /**
     * Resolve structured Country Details strictly from a country code (ISO-2 or ISO-3).
     * Strictly adheres to data-integrity principles:
     * - Never guesses from vessel name, MMSI, or ownership.
     * - Returns 'Unknown' with neutral icon when code is missing, empty, or unknown.
     * - Provides ISO-2, ISO-3, country name, and flag emoji.
     *
     * @param string|null $code Country code (e.g. 'IDN', 'ID', 'ESP', 'TWN')
     * @return array
     */
    public static function resolveCountryDetails(?string $code): array
    {
        if (empty($code)) {
            return [
                'code' => null,
                'name' => 'Unknown',
                'iso2' => null,
                'iso3' => null,
                'flag_emoji' => '🌐',
                'is_known' => false,
                'label' => '🌐 Unknown',
            ];
        }

        $clean = strtoupper(trim($code));
        if ($clean === 'UNKNOWN' || $clean === 'UN' || $clean === 'XXX' || $clean === '-') {
            return [
                'code' => $clean,
                'name' => 'Unknown',
                'iso2' => null,
                'iso3' => null,
                'flag_emoji' => '🌐',
                'is_known' => false,
                'label' => '🌐 Unknown',
            ];
        }

        $iso2 = self::normalizeToIso2($clean);

        // 1. Check in $iso2Table
        $name = self::$iso2Table[$iso2] ?? null;

        // 2. Fallback search in $midTable
        $entryFound = null;
        if (!$name) {
            foreach (self::$midTable as $entry) {
                if ($entry['iso2'] === $iso2 || ($entry['iso3'] ?? null) === $clean) {
                    $name = $entry['name'];
                    $entryFound = $entry;
                    break;
                }
            }
        } else {
            // Find iso3 for recognized iso2 if available
            foreach (self::$midTable as $entry) {
                if ($entry['iso2'] === $iso2) {
                    $entryFound = $entry;
                    break;
                }
            }
        }

        if (!$name) {
            return [
                'code' => $clean,
                'name' => 'Unknown',
                'iso2' => null,
                'iso3' => null,
                'flag_emoji' => '🌐',
                'is_known' => false,
                'label' => '🌐 Unknown',
            ];
        }

        $flagEmoji = self::getFlagEmoji($iso2);

        return [
            'code' => $clean,
            'name' => $name,
            'iso2' => $iso2,
            'iso3' => strlen($clean) === 3 ? $clean : ($entryFound['iso3'] ?? null),
            'flag_emoji' => $flagEmoji,
            'is_known' => true,
            'label' => "{$flagEmoji} {$name}",
        ];
    }

    /**
     * Resolve Flag State metadata for a Vessel
     * Strictly adheres to maritime intelligence verification principles:
     * - Does NOT guess from vessel name or owner.
     * - MMSI MID is treated as administrative ITU indicator, not definitive proof.
     * - Returns explicit source provenance.
     * - Returns 'Flag state unknown' when missing.
     */
    public static function resolveForVessel(?Vessel $vessel): array
    {
        if (!$vessel) {
            return self::unknownResponse();
        }

        $explicitFlag = !empty($vessel->flag_state) ? strtoupper(trim($vessel->flag_state)) : null;
        $mmsi = !empty($vessel->mmsi) ? preg_replace('/\D/', '', $vessel->mmsi) : null;

        $midCountry = self::lookupMid($mmsi);

        // Case 1: Explicit flag state recorded in vessel registry/telemetry
        if ($explicitFlag) {
            $iso2 = self::normalizeToIso2($explicitFlag);
            $countryName = self::$iso2Table[$iso2] ?? null;
            if (!$countryName) {
                foreach (self::$midTable as $entry) {
                    if ($entry['iso2'] === $iso2) {
                        $countryName = $entry['name'];
                        break;
                    }
                }
            }
            $countryName = $countryName ?? $explicitFlag;

            // Check for conflict between explicit flag and MMSI MID allocation
            $hasConflict = false;
            $conflictNote = null;
            if ($midCountry && $midCountry['iso2'] !== $iso2) {
                $hasConflict = true;
                $conflictNote = "MMSI MID ({$midCountry['iso2']} - {$midCountry['name']}) differs from explicit flag state ({$iso2} - {$countryName}). Potensi pergantian bendera (reflagging) atau alokasi transponder.";
            }

            return [
                'status' => $hasConflict ? 'conflict_detected' : 'verified_explicit',
                'country_name' => $countryName,
                'iso2' => $iso2,
                'flag_emoji' => self::getFlagEmoji($iso2),
                'source' => 'Explicit Registry / Ingestion Feed',
                'is_verified' => !$hasConflict,
                'is_known' => true,
                'has_conflict' => $hasConflict,
                'conflict_note' => $conflictNote,
                'mid_indicator' => $midCountry ? "{$midCountry['iso2']} ({$midCountry['name']})" : null,
                'label' => self::getFlagEmoji($iso2) . ' ' . $countryName . ' (' . $iso2 . ')',
            ];
        }

        // Case 2: No explicit flag, but MMSI MID allocation is available
        // Rule: MMSI MID is only an administrative clue, not definitive current flag state
        if ($midCountry) {
            $iso2 = $midCountry['iso2'];
            $countryName = $midCountry['name'];

            return [
                'status' => 'mid_administrative_indicator',
                'country_name' => $countryName,
                'iso2' => $iso2,
                'flag_emoji' => self::getFlagEmoji($iso2),
                'source' => 'ITU MMSI MID Prefix Allocation (Administrative Clue Only)',
                'is_verified' => false,
                'is_known' => true,
                'has_conflict' => false,
                'conflict_note' => 'MMSI MID hanya petunjuk administratif awal alokasi nomor radio ITU, bukan bukti definitif flag state terkini kapal.',
                'mid_indicator' => "{$iso2} ({$countryName})",
                'label' => self::getFlagEmoji($iso2) . ' ' . $countryName . ' [' . $iso2 . ' - MID Belum Terverifikasi]',
            ];
        }

        // Case 3: Flag state unknown / Not available
        return self::unknownResponse();
    }

    /**
     * Resolve Flag State from ISO code and optional MMSI
     */
    public static function resolveFromCodeAndMmsi(?string $flagCode, ?string $mmsi = null): array
    {
        $explicitFlag = !empty($flagCode) ? strtoupper(trim($flagCode)) : null;
        $cleanMmsi = !empty($mmsi) ? preg_replace('/\D/', '', $mmsi) : null;
        $midCountry = self::lookupMid($cleanMmsi);

        if ($explicitFlag && $explicitFlag !== 'UN' && $explicitFlag !== 'UNKNOWN') {
            $iso2 = self::normalizeToIso2($explicitFlag);
            $countryName = self::$iso2Table[$iso2] ?? null;
            if (!$countryName) {
                foreach (self::$midTable as $entry) {
                    if ($entry['iso2'] === $iso2) {
                        $countryName = $entry['name'];
                        break;
                    }
                }
            }
            $countryName = $countryName ?? $explicitFlag;
            $hasConflict = ($midCountry && $midCountry['iso2'] !== $iso2);

            return [
                'status' => $hasConflict ? 'conflict_detected' : 'verified_explicit',
                'country_name' => $countryName,
                'iso2' => $iso2,
                'flag_emoji' => self::getFlagEmoji($iso2),
                'source' => 'Explicit Registry / Ingestion Feed',
                'is_verified' => !$hasConflict,
                'is_known' => true,
                'has_conflict' => $hasConflict,
                'conflict_note' => $hasConflict ? "MMSI MID ({$midCountry['iso2']}) berbeda dengan explicit flag ({$iso2})." : null,
                'mid_indicator' => $midCountry ? "{$midCountry['iso2']} ({$midCountry['name']})" : null,
                'label' => self::getFlagEmoji($iso2) . ' ' . $countryName . ' (' . $iso2 . ')',
            ];
        }

        if ($midCountry) {
            $iso2 = $midCountry['iso2'];
            $countryName = $midCountry['name'];

            return [
                'status' => 'mid_administrative_indicator',
                'country_name' => $countryName,
                'iso2' => $iso2,
                'flag_emoji' => self::getFlagEmoji($iso2),
                'source' => 'ITU MMSI MID Prefix Allocation (Administrative Clue Only)',
                'is_verified' => false,
                'is_known' => true,
                'has_conflict' => false,
                'conflict_note' => 'MMSI MID hanya petunjuk administratif ITU, bukan bukti definitif flag state terkini.',
                'mid_indicator' => "{$iso2} ({$countryName})",
                'label' => self::getFlagEmoji($iso2) . ' ' . $countryName . ' [' . $iso2 . ' - MID]',
            ];
        }

        return self::unknownResponse();
    }

    /**
     * Get database query filter criteria for a country code (ISO-2 or ISO-3).
     * Returns:
     * - 'is_unknown': bool
     * - 'iso2': string|null
     * - 'codes': array of matched flag_state codes in DB (e.g. ['KR', 'KOR'])
     * - 'mids': array of ITU MID prefix strings (e.g. ['440', '441'])
     */
    public static function getFilterCriteria(?string $countryInput): array
    {
        if (empty($countryInput)) {
            return [
                'is_unknown' => false,
                'iso2' => null,
                'codes' => [],
                'mids' => [],
            ];
        }

        $clean = strtoupper(trim($countryInput));
        if ($clean === 'UNKNOWN' || $clean === 'UN' || $clean === 'XXX' || $clean === '-') {
            return [
                'is_unknown' => true,
                'iso2' => 'UN',
                'codes' => ['UNKNOWN', 'UN', 'XXX', '-'],
                'mids' => [],
            ];
        }

        $iso2 = self::normalizeToIso2($clean);
        $codes = [$iso2];
        if (strlen($clean) === 3) {
            $codes[] = $clean;
        }

        $mids = [];
        foreach (self::$midTable as $mid => $entry) {
            if ($entry['iso2'] === $iso2) {
                if (!empty($entry['iso3'])) {
                    $codes[] = $entry['iso3'];
                }
                $mids[] = (string) $mid;
            }
        }

        return [
            'is_unknown' => false,
            'iso2' => $iso2,
            'codes' => array_values(array_unique(array_filter($codes))),
            'mids' => array_values(array_unique($mids)),
        ];
    }

    /**
     * Lookup ITU Maritime Identification Digits (MID) from 9-digit MMSI
     */
    public static function lookupMid(?string $mmsi): ?array
    {
        if (empty($mmsi) || strlen($mmsi) < 3) {
            return null;
        }

        $mid = substr($mmsi, 0, 3);
        return self::$midTable[$mid] ?? null;
    }

    /**
     * Convert 2-letter ISO country code into Unicode Flag Emoji
     */
    public static function getFlagEmoji(?string $iso2): string
    {
        if (empty($iso2) || strlen($iso2) !== 2 || !ctype_alpha($iso2)) {
            return '🌐';
        }

        $iso2 = strtoupper($iso2);
        // Regional Indicator Symbols start at 0x1F1E6 for 'A'
        $firstChar = mb_chr(0x1F1E6 + ord($iso2[0]) - ord('A'), 'UTF-8');
        $secondChar = mb_chr(0x1F1E6 + ord($iso2[1]) - ord('A'), 'UTF-8');

        return $firstChar . $secondChar;
    }

    /**
     * Normalize alpha-3 or variations into standard ISO alpha-2
     */
    protected static function normalizeToIso2(string $code): string
    {
        $code = strtoupper(trim($code));

        $iso3Map = [
            'IDN' => 'ID',
            'CHN' => 'CN',
            'PAN' => 'PA',
            'LBR' => 'LR',
            'MHL' => 'MH',
            'ESP' => 'ES',
            'JPN' => 'JP',
            'KOR' => 'KR',
            'TWN' => 'TW',
            'VNM' => 'VN',
            'THA' => 'TH',
            'MYS' => 'MY',
            'PHL' => 'PH',
            'SGP' => 'SG',
            'RUS' => 'RU',
            'USA' => 'US',
            'AUS' => 'AU',
            'NZL' => 'NZ',
            'GBR' => 'GB',
            'NOR' => 'NO',
            'DNK' => 'DK',
            'FRA' => 'FR',
            'ITA' => 'IT',
            'GRC' => 'GR',
            'NLD' => 'NL',
            'DEU' => 'DE',
            'BLZ' => 'BZ',
            'BHS' => 'BS',
            'BMU' => 'BM',
            'CYM' => 'KY',
            'CYP' => 'CY',
            'MLT' => 'MT',
            'VUT' => 'VU',
            'KHM' => 'KH',
            'IND' => 'IN',
            'BRA' => 'BR',
            'ARG' => 'AR',
            'CHL' => 'CL',
            'ECU' => 'EC',
            'PER' => 'PE',
            'ZAF' => 'ZA',
            'CAN' => 'CA',
            'MEX' => 'MX',
            'ISL' => 'IS',
            'LTU' => 'LT',
            'LVA' => 'LV',
            'POL' => 'PL',
            'SYC' => 'SC',
            'MUS' => 'MU',
        ];

        if (isset($iso3Map[$code])) {
            return $iso3Map[$code];
        }

        foreach (self::$midTable as $entry) {
            if (($entry['iso3'] ?? null) === $code) {
                return $entry['iso2'];
            }
        }

        if (strlen($code) === 2 && ctype_alpha($code)) {
            return $code;
        }

        return $code;
    }

    protected static function unknownResponse(): array
    {
        return [
            'status' => 'unknown',
            'country_name' => 'Flag state unknown',
            'iso2' => 'UN',
            'flag_emoji' => '🌐',
            'source' => 'Not available',
            'is_verified' => false,
            'is_known' => false,
            'has_conflict' => false,
            'conflict_note' => null,
            'mid_indicator' => null,
            'label' => '🌐 Flag state unknown',
        ];
    }
}
