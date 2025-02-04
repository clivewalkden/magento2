<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\QuoteGraphQl\Model\Resolver;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Helper\Error\AggregateExceptionMessageFormatter;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;
use Magento\QuoteGraphQl\Model\Cart\PlaceOrder as PlaceOrderModel;
use Magento\QuoteGraphQl\Model\Cart\PlaceOrderMutexInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Resolver for placing order after payment method has already been set
 */
class PlaceOrder implements ResolverInterface
{
    /**
     * @var GetCartForUser
     */
    private $getCartForUser;

    /**
     * @var PlaceOrderModel
     */
    private $placeOrder;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var AggregateExceptionMessageFormatter
     */
    private $errorMessageFormatter;

    /**
     * @var PlaceOrderMutexInterface
     */
    private $placeOrderMutex;

    /**
     * @param GetCartForUser $getCartForUser
     * @param PlaceOrderModel $placeOrder
     * @param OrderRepositoryInterface $orderRepository
     * @param AggregateExceptionMessageFormatter $errorMessageFormatter
     * @param PlaceOrderMutexInterface|null $placeOrderMutex
     */
    public function __construct(
        GetCartForUser $getCartForUser,
        PlaceOrderModel $placeOrder,
        OrderRepositoryInterface $orderRepository,
        AggregateExceptionMessageFormatter $errorMessageFormatter,
        ?PlaceOrderMutexInterface $placeOrderMutex = null
    ) {
        $this->getCartForUser = $getCartForUser;
        $this->placeOrder = $placeOrder;
        $this->orderRepository = $orderRepository;
        $this->errorMessageFormatter = $errorMessageFormatter;
        $this->placeOrderMutex = $placeOrderMutex ?: ObjectManager::getInstance()->get(PlaceOrderMutexInterface::class);
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        if (empty($args['input']['cart_id'])) {
            throw new GraphQlInputException(__('Required parameter "cart_id" is missing'));
        }

        return $this->placeOrderMutex->execute(
            $args['input']['cart_id'],
            \Closure::fromCallable([$this, 'run']),
            [$field, $context, $info, $args]
        );
    }

    /**
     * Run the resolver.
     *
     * @param Field $field
     * @param ContextInterface $context
     * @param ResolveInfo $info
     * @param array|null $args
     * @return array[]
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function run(Field $field, ContextInterface $context, ResolveInfo $info, ?array $args): array
    {
        $maskedCartId = $args['input']['cart_id'];
        $userId = (int)$context->getUserId();
        $storeId = (int)$context->getExtensionAttributes()->getStore()->getId();

        try {
            $cart = $this->getCartForUser->getCartForCheckout($maskedCartId, $userId, $storeId);
            $orderId = $this->placeOrder->execute($cart, $maskedCartId, $userId);
            $order = $this->orderRepository->get($orderId);
        } catch (LocalizedException $e) {
            throw $this->errorMessageFormatter->getFormatted(
                $e,
                __('Unable to place order: A server error stopped your order from being placed. ' .
                    'Please try to place your order again'),
                'Unable to place order',
                $field,
                $context,
                $info
            );
        }

        return [
            'order' => [
                'order_number' => $order->getIncrementId(),
                // @deprecated The order_id field is deprecated, use order_number instead
                'order_id' => $order->getIncrementId(),
            ],
        ];
    }
}
