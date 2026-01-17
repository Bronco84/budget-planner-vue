<?php

namespace App\Traits;

trait InstitutionDomainMapping
{
    /**
     * Known institution name to domain mappings for favicon lookup.
     * Keys should be lowercase for matching.
     *
     * @return array<string, string>
     */
    protected static function getInstitutionDomains(): array
    {
        return [
            'chase' => 'chase.com',
            'bank of america' => 'bankofamerica.com',
            'wells fargo' => 'wellsfargo.com',
            'citibank' => 'citi.com',
            'citi' => 'citi.com',
            'capital one' => 'capitalone.com',
            'us bank' => 'usbank.com',
            'pnc' => 'pnc.com',
            'truist' => 'truist.com',
            'td bank' => 'td.com',
            'fifth third' => '53.com',
            'regions' => 'regions.com',
            'regions bank' => 'regions.com',
            'citizens' => 'citizensbank.com',
            'huntington' => 'huntington.com',
            'keybank' => 'key.com',
            'ally' => 'ally.com',
            'discover' => 'discover.com',
            'synchrony' => 'synchrony.com',
            'american express' => 'americanexpress.com',
            'amex' => 'americanexpress.com',
            'usaa' => 'usaa.com',
            'navy federal' => 'navyfederal.org',
            'schwab' => 'schwab.com',
            'charles schwab' => 'schwab.com',
            'fidelity' => 'fidelity.com',
            'vanguard' => 'vanguard.com',
            'e*trade' => 'etrade.com',
            'etrade' => 'etrade.com',
            'robinhood' => 'robinhood.com',
            'paypal' => 'paypal.com',
            'venmo' => 'venmo.com',
            'chime' => 'chime.com',
            'sofi' => 'sofi.com',
            'marcus' => 'marcus.com',
            'goldman sachs' => 'goldmansachs.com',
            'home depot' => 'homedepot.com',
            'lowes' => 'lowes.com',
            "lowe's" => 'lowes.com',
            'target' => 'target.com',
            'walmart' => 'walmart.com',
            'amazon' => 'amazon.com',
            'apple' => 'apple.com',
            'coinbase' => 'coinbase.com',
            'navy federal credit union' => 'navyfederal.org',
            'pentagon federal' => 'penfed.org',
            'penfed' => 'penfed.org',
            'mountain america' => 'macu.com',
            'credit karma' => 'creditkarma.com',
            'betterment' => 'betterment.com',
            'wealthfront' => 'wealthfront.com',
            'merrill' => 'ml.com',
            'merrill lynch' => 'ml.com',
            'morgan stanley' => 'morganstanley.com',
            'citi cards' => 'citi.com',
            'barclays' => 'barclays.com',
        ];
    }

    /**
     * Get the domain for an institution name by searching the mapping.
     *
     * @param string $institutionName
     * @return string|null
     */
    protected static function getDomainForInstitution(string $institutionName): ?string
    {
        $nameLower = strtolower($institutionName);

        foreach (static::getInstitutionDomains() as $key => $domain) {
            if (str_contains($nameLower, $key)) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * Get a Google S2 favicon URL for a domain.
     *
     * @param string $domain
     * @param int $size
     * @return string
     */
    protected static function getGoogleFaviconUrl(string $domain, int $size = 128): string
    {
        return "https://www.google.com/s2/favicons?domain={$domain}&sz={$size}";
    }

    /**
     * Get a Google S2 favicon URL for an institution name.
     *
     * @param string $institutionName
     * @param int $size
     * @return string|null
     */
    protected static function getFaviconUrlForInstitution(string $institutionName, int $size = 128): ?string
    {
        $domain = static::getDomainForInstitution($institutionName);

        if (!$domain) {
            return null;
        }

        return static::getGoogleFaviconUrl($domain, $size);
    }

    /**
     * Extract domain from a URL.
     *
     * @param string $url
     * @return string|null
     */
    protected static function extractDomainFromUrl(string $url): ?string
    {
        $parsed = parse_url($url);

        if (!isset($parsed['host'])) {
            return null;
        }

        return $parsed['host'];
    }
}
