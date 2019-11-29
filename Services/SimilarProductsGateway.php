<?php
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
use Doctrine\ORM\Query\Expr;
use Shopware\Bundle\StoreFrontBundle\Gateway\SimilarProductsGatewayInterface;
use Shopware\Bundle\StoreFrontBundle\Service\ContextServiceInterface;
use Shopware\Bundle\StoreFrontBundle\Service\ListProductServiceInterface;
use Shopware\Bundle\StoreFrontBundle\Struct;
use Shopware\Models\Article\Article;

class SimilarProductsGateway implements SimilarProductsGatewayInterface
{

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var ListProductServiceInterface
     */
    private $productService;
    /**
     * @var ContextServiceInterface
     */
    private $contextService;
    /**
     * @var array
     */
    private $config;

    /**
     * SimilarProductsService constructor.
     */
    public function __construct(
        ListProductServiceInterface $productService,
        ContextServiceInterface $contextService,
        EntityManagerInterface $entityManager,
        array $config
    ) {
        $this->entityManager = $entityManager;
        $this->productService = $productService;
        $this->contextService = $contextService;
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function getList($products, Struct\ShopContextInterface $context)
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
    public function get(Struct\BaseProduct $product, Struct\ShopContextInterface $context)
    {
        $listProduct = $this->productService->get($product->getNumber(), $context);

        if ($listProduct === null) {
            return [];
        }

        $nameParts = explode(' ', $listProduct->getName());

        $price = $listProduct->getCheapestPrice();
        if ($price === null) {
            return [];
        }
        $price = $price->getCalculatedPrice();
        $minDifference = clamp(
            $price - ($price * (1 - ($this->config['baseDifference'] / 100))),
            $this->config['minDifference'],
            $this->config['maxDifference']
        );
        $maxDifference = clamp(
            $price - ($price * (1 + ($this->config['baseDifference'] / 100))),
            $this->config['minDifference'],
            $this->config['maxDifference']
        );

        $qb = $this->entityManager->createQueryBuilder();
        $query = $qb->from(Article::class, 'article')
            ->innerJoin('article.mainDetail', 'detail', Expr\Join::WITH, 'detail.active = 1')
            ->innerJoin('detail.prices', 'price', Expr\Join::WITH, 'price.from = 1 AND price.customerGroupKey = \'EK\'')
            ->select('detail.number as number')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('article.active', '1'),
                    $qb->expr()->neq('article.id', $product->getId()),

                    // Name Logic
                    $qb->expr()->like('article.name', ':name'),

                    // Price Logic
                    $qb->expr()->andX(
                    // Has to be cheaper than max price
                        $qb->expr()->lte('price.price', $price + $maxDifference),
                        // Has to be more expensive than min price
                        $qb->expr()->gte('price.price', $price - $minDifference)
                    )
                )
            )
            ->setMaxResults($this->config['maxResults'])
            ->setParameter('name', $nameParts[0].'%');

        $result = $query->getQuery()->getArrayResult();

        $foundProducts = [];
        foreach ($result as $p) {
            $foundProducts[] = $p['number'];
        }

        return $foundProducts;
    }
}

function clamp(float $x, float $low, float $high)
{
    if ($x > $high) {
        return $high;
    }

    if ($x < $low) {
        return $low;
    }

    return $x;
}
