<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CountriesIso2Seeder extends Seeder
{
    public function run(): void
    {
        $map = [
            'ENGLAND' => 'ENG',
            'SCOTLAND' => 'SCO',
            'WALES' => 'WAL',
            'NORTHERN IRELAND' => 'NIR',

            'SPAIN' => 'ES',
            'FRANCE' => 'FR',
            'GERMANY' => 'DE',
            'ITALY' => 'IT',
            'PORTUGAL' => 'PT',
            'NETHERLANDS' => 'NL',
            'BELGIUM' => 'BE',
            'DENMARK' => 'DK',
            'NORWAY' => 'NO',
            'SWEDEN' => 'SE',
            'FINLAND' => 'FI',
            'ICELAND' => 'IS',

            'BRAZIL' => 'BR',
            'ARGENTINA' => 'AR',
            'URUGUAY' => 'UY',
            'COLOMBIA' => 'CO',
            'ECUADOR' => 'EC',
            'MEXICO' => 'MX',
            'UNITED STATES' => 'US',
            'CANADA' => 'CA',

            'NIGERIA' => 'NG',
            'GHANA' => 'GH',
            'SENEGAL' => 'SN',
            'CAMEROON' => 'CM',
            'MOROCCO' => 'MA',
            'EGYPT' => 'EG',
            'ALGERIA' => 'DZ',
            'TUNISIA' => 'TN',

            'POLAND' => 'PL',
            'CZECH REPUBLIC' => 'CZ',
            'SLOVAKIA' => 'SK',
            'AUSTRIA' => 'AT',
            'SWITZERLAND' => 'CH',
            'TURKEY' => 'TR',
            'UKRAINE' => 'UA',
            'RUSSIA' => 'RU',
            'SERBIA' => 'RS',
            'CROATIA' => 'HR',
        ];

        $now = now();

        foreach ($map as $code => $iso2) {
            DB::table('countries')
                ->where('code', $code)
                ->update([
                    'iso2' => $iso2,
                    'updated_at' => $now,
                ]);
        }
    }
}
