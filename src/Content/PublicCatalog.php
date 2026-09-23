<?php

declare(strict_types=1);

namespace HScript\Content;

use HScript\Cache\CatalogCache;
use HScript\Database\Connection;

/** Public listing snapshots shared by page controllers and sitemap discovery. */
final class PublicCatalog
{
    public function __construct(private Connection $db, private CatalogCache $cache)
    {
    }

    public function news(): array
    {
        $now = gmdate('YmdHis');
        $rows = $this->cache->remember(CatalogCache::NEWS, 'public-active', fn(): array => $this->db->fetchIDRows(
            $this->db->select('News', '*', '(nDBegin=0 or nDBegin<=?) and (nDEnd=0 or nDEnd>=?)',
                [$now, $now], 'nAttn desc, nTS desc, nID desc'), false, 'nID'
        ));
        // Expiration must take effect even while the catalog snapshot is cached.
        return array_filter($rows, static fn(array $row): bool => self::newsIsPublished($row, $now));
    }

    public static function newsIsPublished(array $row, string $now): bool
    {
        return $row !== [] && (empty($row['nDBegin']) || $row['nDBegin'] <= $now)
            && (empty($row['nDEnd']) || $row['nDEnd'] >= $now);
    }

    public function faq(): array
    {
        return $this->cache->remember(CatalogCache::FAQ, 'public-visible', fn(): array => $this->db->fetchIDRows(
            $this->db->select('FAQ', '*', 'fHidden=0', [], 'fCat, fOrder, fID'), false, 'fID'
        ));
    }

    /** Same default order and first page as the public review controller. */
    public function reviews(int $limit): array
    {
        return $this->db->fetchRows($this->db->select('Review', 'oID, oText', 'oState=1', [],
            'oOrder desc, oTS desc, oID desc', (string)$limit));
    }
}
