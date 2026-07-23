<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

final class OrganizationClassifier
{
    /** @var array<string, array{label:string,icon:string,featured:bool}> */
    private const META = [
        'research' => ['label' => 'Research / Education', 'icon' => '🎓', 'featured' => true],
        'government' => ['label' => 'Government', 'icon' => '🏛', 'featured' => true],
        'company' => ['label' => 'Company', 'icon' => '🏢', 'featured' => true],
        'isp' => ['label' => 'ISP / Communication', 'icon' => '📡', 'featured' => false],
        'cloud' => ['label' => 'Cloud', 'icon' => '☁', 'featured' => false],
        'proxy' => ['label' => 'VPN / Proxy candidate', 'icon' => '🛡', 'featured' => false],
        'bot' => ['label' => 'Bot', 'icon' => '🤖', 'featured' => false],
        'unknown' => ['label' => 'Unknownnown', 'icon' => '❔', 'featured' => false],
    ];

    /**
     * @param array<int|string, string> $overrides ASN => category
     * @return array{category:string,label:string,icon:string,featured:bool,confidence:int,reason:string}
     */
    public static function classify(?int $asn, string $organization, bool $isBot, array $overrides = []): array
    {
        if ($isBot) {
            return self::result('bot', 100, 'Bot detection');
        }

        $normalizedOverrides = self::normalizeOverrides($overrides);
        if ($asn !== null && isset($normalizedOverrides[$asn])) {
            return self::result($normalizedOverrides[$asn], 100, 'Admin override');
        }

        $org = trim($organization);
        if ($org === '') {
            return self::result('unknown', 0, 'No ASN organizationrganization');
        }

        $value = self::lower($org);

        if (self::matches($value, [
            'national institute of informatics', 'research organization of information and systems',
            'university', 'universität', 'universite', 'college', 'academic', 'academy',
            'research', 'institute', 'informatics', 'laborator', 'education', 'school',
            'science foundation', 'scientific', 'observatory',
        ])) {
            return self::result('research', 94, 'Research / education keyword');
        }

        if (self::matches($value, [
            'ministry', 'government', 'govt', 'government office', 'agency', 'prefecture',
            'municipal', 'municipality', 'city of ', 'town of ', 'village of ', 'county of ',
            'police', 'defence', 'defense', 'parliament', 'senate', 'state of ',
            'public administration', 'meteorological', 'fire department',
        ])) {
            return self::result('government', 92, 'Government keyword');
        }

        if (self::matches($value, [
            'packet hub', 'packethub', 'proxy', ' vpn', 'vpn ', 'virtual private',
            'anonym', 'privacy network', 'tor exit', 'residential proxy',
        ])) {
            return self::result('proxy', 88, 'VPN / proxy keyword');
        }

        if (self::matches($value, [
            'amazon.com', 'amazon technologies', 'microsoft corporation', 'google llc',
            'cloudflare', 'digitalocean', 'linode', 'vultr', 'oracle cloud', 'alibaba cloud',
            'huawei cloud', 'tencent cloud', 'data center', 'data centre', 'datacenter',
            'hosting', 'hostroyale', 'oculus networks', 'constant company', 'choopa',
            'leaseweb', 'hetzner', 'ovh', 'contabo', 'akamai', 'fastly',
        ])) {
            return self::result('cloud', 90, 'Cloud / datacenter keyword');
        }

        if (self::matches($value, [
            'telecom', 'communications', 'broadband', 'cable', 'mobile', 'wireless',
            'internet service', 'internet provider', 'docomo', 'softbank', 'kddi',
            'ntt', 'biglobe', 'arteria', 'so-net', 'sonet', 'optage', 'asahi net',
            'qtnet', 'qt net', 'comcast', 'verizon', 'china unicom', 'china169',
            'telefonica', 'vodafone', 'orange s.a', 'deutsche telekom',
        ])) {
            return self::result('isp', 86, 'ISP keyword');
        }

        if (self::matches($value, [
            'corporation', ' corp', 'inc.', ' inc', 'limited', ' ltd', 'llc', 'co.,',
            'company', 'gmbh', 's.a.', 's.a', 'plc', 'pvt', 'holdings', 'group',
        ])) {
            return self::result('company', 76, 'Corporate name');
        }

        return self::result('company', 55, 'ASN organization presentnization present');
    }

    /** @return array<string, array{label:string,icon:string,featured:bool}> */
    public static function categories(): array
    {
        return self::META;
    }

    /**
     * Parses lines such as "AS2907=research" or "2907 government".
     *
     * @return array<int, string>
     */
    public static function parseOverrides(string $text): array
    {
        $result = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim(preg_replace('/\s*[#;].*$/', '', $line) ?? '');
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^(?:AS)?\s*(\d{1,10})\s*(?:=|:|\s)\s*([a-z_-]+)$/i', $line, $matches)) {
                continue;
            }
            $asn = (int)$matches[1];
            $category = strtolower(str_replace('-', '_', $matches[2]));
            if ($asn > 0 && isset(self::META[$category])) {
                $result[$asn] = $category;
            }
        }
        return $result;
    }

    /**
     * @param array<int|string, string> $overrides
     * @return array<int, string>
     */
    private static function normalizeOverrides(array $overrides): array
    {
        $result = [];
        foreach ($overrides as $asn => $category) {
            $number = (int)preg_replace('/\D+/', '', (string)$asn);
            $category = strtolower(str_replace('-', '_', trim((string)$category)));
            if ($number > 0 && isset(self::META[$category])) {
                $result[$number] = $category;
            }
        }
        return $result;
    }

    /** @return array{category:string,label:string,icon:string,featured:bool,confidence:int,reason:string} */
    private static function result(string $category, int $confidence, string $reason): array
    {
        $meta = self::META[$category] ?? self::META['unknown'];
        return [
            'category' => $category,
            'label' => $meta['label'],
            'icon' => $meta['icon'],
            'featured' => $meta['featured'],
            'confidence' => max(0, min(100, $confidence)),
            'reason' => $reason,
        ];
    }

    /** @param list<string> $needles */
    private static function matches(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
