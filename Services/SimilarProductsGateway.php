<?php declare(strict_types=1);
/**
 * Einrichtungshaus Ostermann GmbH & Co. KG - SimilarArticles
 *
 * Define the Similar Articles based on Rules
 *
 * @package   OstSimilarArticles
 *
 * @author    Tim Windelschmidt <tim.windelschmidt@ostermann.de>
 * @copyright 2019 Einrichtungshaus Ostermann GmbH & Co. KG
 * @license   proprietary
 */

namespace OstSimilarArticles\Services;

use Doctrine\ORM\EntityManagerInterface;
use PDO;
use Shopware\Bundle\StoreFrontBundle\Gateway\SimilarProductsGatewayInterface;
use Shopware\Bundle\StoreFrontBundle\Service\ListProductServiceInterface;
use Shopware\Bundle\StoreFrontBundle\Struct;

class SimilarProductsGateway implements SimilarProductsGatewayInterface
{
    /**
     * @var ListProductServiceInterface
     */
    private $productService;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var array
     */
    private $config;

    /**
     * @var array
     */
    private $properties;

    /**
     * SimilarProductsGateway constructor.
     * @param ListProductServiceInterface $productService
     * @param EntityManagerInterface $entityManager
     * @param array $config
     */
    public function __construct(
        ListProductServiceInterface $productService,
        EntityManagerInterface $entityManager,
        array $config
    ) {
        $this->productService = $productService;
        $this->entityManager = $entityManager;
        $this->config = $config;

        foreach (explode(';', $this->config['samePropertyNames']) as $group) {
            if ($group === '') {
                continue;
            }

            var_dump($group);
            [$hwg, $properties] = explode(':', $group);
            $this->properties[$hwg] = explode(',', $properties);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getList($products, Struct\ShopContextInterface $context): array
    {
        $data = [];

        foreach ($products as $product) {
            $data[$product->getId()] = $this->get($product, $context);
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function get(Struct\BaseProduct $product, Struct\ShopContextInterface $context): array
    {
        /** @var Struct\ProductContextInterface $context */
        $listProduct = $this->productService->get($product->getNumber(), $context);
        if ($listProduct === null) {
            return [];
        }

        $price = $listProduct->getCheapestPrice();
        if ($price === null) {
            return [];
        }

        $price = $price->getCalculatedPrice();
        $minDifference = $this->clamp(
            $price - ($price * (1 - ($this->config['baseDifference'] / 100))),
            (float)$this->config['minDifference'],
            (float)$this->config['maxDifference']
        );
        $maxDifference = $this->clamp(
            $price - ($price * (1 + ($this->config['baseDifference'] / 100))),
            (float)$this->config['minDifference'],
            (float)$this->config['maxDifference']
        );

        $db = $this->entityManager->getConnection();
        $qb = $db->createQueryBuilder();

        $filters = [
            // Name Logic
            $qb->expr()->like('articles.name', $qb->createNamedParameter(explode(' ', $listProduct->getName())[0].'%')),

            // Price Logic
            $qb->expr()->andX(
            // Has to be cheaper than max price
                $qb->expr()->lte('prices.price', $price + $maxDifference),
                // Has to be more expensive than min price
                $qb->expr()->gte('prices.price', $price - $minDifference)
            )
        ];

        if (isset($this->properties[$product->getAttribute('attr2')])) {
            $properties = $this->properties[$product->getAttribute('attr2')];

            $result = $db->createQueryBuilder()->from('s_articles', 'articles')
                ->leftJoin('articles', 's_filter_articles', 'filter_articles', 'filter_articles.articleID = articles.id')
                ->leftJoin('filter_articles', 's_filter_values', 'filter_values', 'filter_articles.valueID = filter_values.id')
                ->leftJoin('filter_values', 's_filter_options', 'filter_options', 'filter_values.optionID = filter_options.id')
                ->select('filter_options.name as option_name, filter_options.id as filter_id, filter_values.id as value_id')
                ->where('articles.id = ? AND filter_options.id IS NOT NULL AND filter_values.id IS NOT NULL')
                ->setParameter(0, $product->getId())
                ->execute()->fetchAll(PDO::FETCH_ASSOC);

            if (count($result) === 0) {
                goto skip_product_filters;
            }

            $productFilters = [];
            foreach ($result as $productFilter) {
                if (!in_array($productFilter['option_name'], $properties, true)) {
                    continue;
                }

                $productFilters[$productFilter['filter_id']][] = $productFilter['value_id'];
            }

            $expr = [];
            foreach ($productFilters as $id => $values) {
                $expr[] = $qb->expr()->andX(
                    $qb->expr()->eq('filter_options.id', $id),
                    $qb->expr()->in('filter_values.id', $values)
                );
            }

            $filters[] = $qb->expr()->orX(...$expr);

            skip_product_filters:
        }

        $query = $qb->from('s_articles', 'articles')
            ->innerJoin('articles', 's_articles_details', 'details', 'articles.main_detail_id = details.id')
            ->innerJoin('details', 's_articles_prices', 'prices', 'prices.articledetailsID = details.id')

            ->leftJoin('details', 's_filter_articles', 'filter_articles', 'filter_articles.articleID = articles.id')
            ->leftJoin('details', 's_filter_values', 'filter_values', 'filter_articles.valueID = filter_values.id')
            ->leftJoin('filter_values', 's_filter_options', 'filter_options', 'filter_values.optionID = filter_options.id')

            ->select('details.ordernumber')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('articles.active', '1'),
                    $qb->expr()->eq('details.active', '1'),
                    $qb->expr()->eq('prices.from', '1'),
                    $qb->expr()->eq('prices.pricegroup', '"EK"'),
                    $qb->expr()->neq('articles.id', $product->getId()),
                    $qb->expr()->andX(...$filters) // Use all filters
                )
            )
            ->groupBy('articles.id')
            ->setMaxResults($this->config['maxResults']);

        $result = $query->execute()->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function ($article) {
            return $article['ordernumber'];
        }, $result);
    }

    private function clamp(float $x, float $low, float $high): float
    {
        if ($x > $high) {
            return $high;
        }

        if ($x < $low) {
            return $low;
        }

        return $x;
    }
}
