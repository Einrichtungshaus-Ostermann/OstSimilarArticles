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
    public function __construct(ListProductServiceInterface $productService, ContextServiceInterface $contextService, EntityManagerInterface $entityManager, array $config)
    {
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
        $minDifference = max($price * (1 - ($this->config['baseDifference'] / 100)), $this->config['minDifference']);
        $maxDifference = min($price * (1 + ($this->config['baseDifference'] / 100)), $this->config['maxDifference']);
        $maxDifference = max($maxDifference, $minDifference);

        $qb =$this->entityManager->createQueryBuilder();
        $query = $qb->from(Article::class, 'article')
            ->innerJoin('article.mainDetail', 'detail', Expr\Join::WITH, 'detail.active = 1')
            ->select('detail.number as number')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('article.active', '1'),
                    $qb->expr()->like('article.name', ':name')
                )
            )
            ->setParameter('name', $nameParts[0] .'%');

        $result = $query->getQuery()->getArrayResult();

        $foundProducts = [];
        foreach ($result as $p) {
            $foundProducts[] = $p['number'];
        }

        $foundProducts = $this->productService->getList($foundProducts, $context);

        $similarArticles = [];
        foreach ($foundProducts as $foundProduct) {
            // if same skip
            if ($foundProduct->getId() === $listProduct->getId()) {
                continue;
            }

            $currentPrice = $foundProduct->getCheapestPrice();
            // if no price skip
            if ($currentPrice === null) {
                continue;
            }
            $foundProductPrice = $currentPrice->getCalculatedPrice();

            if ($foundProductPrice < $price - $minDifference) {
                continue;
            }

            if ($foundProductPrice > $price + $maxDifference) {
                continue;
            }

            $similarArticles[] = $foundProduct->getNumber();
        }

        return $similarArticles;
    }
}