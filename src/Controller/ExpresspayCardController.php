<?php

namespace Drupal\uc_expresspaycard\Controller;

use Drupal\uc_expresspaycard\Plugin\Ubercart\PaymentMethod\ExpresspayCard;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Controller\ControllerBase;
use Drupal\uc_cart\CartManagerInterface;
use Drupal\uc_order\Entity\Order;
use Drupal\uc_payment\Plugin\PaymentMethodManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller routines for uc_Fondy.
 */
class ExpresspayCardController extends ControllerBase
{

	/**
	 * The cart manager.
	 *
	 * @var \Drupal\uc_cart\CartManager
	 */
	protected $cartManager;
	/**
	 * @var
	 */
	protected $session;

	/**
	 * Constructs a FondyController.
	 *
	 * @param \Drupal\uc_cart\CartManagerInterface $cart_manager
	 *   The cart manager.
	 */
	public function __construct(CartManagerInterface $cart_manager)
	{
		$this->cartManager = $cart_manager;
	}

	/**
	 * @param ContainerInterface $container
	 *
	 * @return static
	 */
	public static function create(ContainerInterface $container)
	{
		return new static(
			$container->get('uc_cart.manager')
		);
	}

	/**
	 * Сообщение при успешном платеже
	 */
	public function complete()
	{

		$orderId = $_REQUEST['ExpressPayAccountNumber'];
		$signature = $_REQUEST['Signature'];
		$order = Order::load($orderId);

		if (!$order) {
			return ['#plain_text' => "Заказ не найден!"];
		}

		$plugin = \Drupal::service('plugin.manager.uc_payment.method')->createFromOrder($order);

		if ($plugin->getPluginId() != 'expresspaycard') {
			throw new AccessDeniedHttpException();
		}

		$configuration = $plugin->getConfiguration();

		$valid = $this->validSignature($configuration, $signature);

		if ($valid == false) {
			uc_order_comment_save($order->id(), 0, 'Эксперсс платежи: Интернет-эквайринг -> Цифровая подпись не совпала!', 'admin');
			return ['#plain_text' => "Эксперсс платежи: Интернет-эквайринг -> Цифровая подпись не совпала!"];
		}

		$order->save();

		$output = 'Счет успешно оплачен.<br/>
		Сумма оплаты: <b>##SUM## BYN</b><br />';

		$output = str_replace("##SUM##", number_format(floatval($order->getTotal()), 2, ',', ''), $output);

		$this->cartManager->completeSale($order);
		return ['#markup' => $output];
	}

	/**
	 * Сообщение при ошибке
	 */
	public function cancel()
	{
		$orderId = $_REQUEST['ExpressPayAccountNumber'];

		$order = Order::load($orderId);

		if (!$order) {
			return ['#plain_text' => "Заказ не найден!"];
		}

		$output_error =
			'<br />
		<h3>Ваш номер заказа: ##ORDER_ID##</h3>
		<p>При выполнении запроса произошла непредвиденная ошибка. Пожалуйста, повторите запрос позже или обратитесь в службу технической поддержки магазина</p>';

		$output_error = str_replace('##ORDER_ID##', $order->id(),  $output_error);

		$this->cartManager->completeSale($order);
		return ['#markup' => $output_error];
	}

	/**
	 * 
	 * Уведомления на сайт от сервиса Экспресс платежи.
	 * 
	 */
	public function notification()
	{
		if ($_SERVER['REQUEST_METHOD'] === 'GET') {
			die('Test OK!');
		}
		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			$json = $_POST['Data'];
			$signature = $_POST['Signature'];
			// Преобразуем из JSON в Object
			$data = json_decode($json);
			$order = Order::load($data->AccountNo);
			$plugin        = \Drupal::service('plugin.manager.uc_payment.method')->createFromOrder($order);
			if ($plugin->getPluginId() != 'expresspaycard') {
				die('FAILED | wrong module');
				header("HTTP/1.0 400 Bad Request");
			}
			$configuration = $plugin->getConfiguration();
			if ($order) {
				if ($configuration['useSignatureForNotif']) {
					if ($signature == $this->computeSignature($json, $configuration['secretWordForNotif'])) {
						$this->updateOrder($data);
						die('OK | payment received');
						header("HTTP/1.0 200 OK");
					} else {
						die('FAILED | wrong notify signature');
						header("HTTP/1.0 400 Bad Request");
					}
				} else {
					$this->updateOrder($data);
					die('OK | payment received');
					header("HTTP/1.0 200 OK");
				}
			} else {
				die('FAILED | ID заказа неизвестен');
				header("HTTP/1.0 200 Bad Request");
			}
		}
	}

	// обновление статуса заказа
	function updateOrder($data)
	{
		// Изменился статус счета
		if ($data->CmdType == '3') {
			// Счет оплачен
			if ($data->Status == '3' || $data->Status == '6') {
				// получение заказа по номеру лицевого счета
				$order = Order::load($data->AccountNo);

				// заказ существует
				if (isset($order)) {
					$order->setStatusId('payment_received')->save();
				}
			}
			// Счет отменён
			if ($data->Status == '5') {

				// получение заказа по номеру лицевого счета
				$order = Order::load($data->AccountNo);

				// заказ существует
				if (isset($order)) {
					$order->setStatusId('canceled')->save();
					die('OK | canceled');
				}
			}
		}
	}

	// Функция генерации и проверки цифровой подписи
	function validSignature($settings, $signature)
	{
		$token = $settings['token'];
		$secret_word = $settings['secretWord'];

		$signature_param = array(
			"AccountNo" => $_REQUEST['ExpressPayAccountNumber'],
		);

		$validSignature = ExpressPayCard::compute_signature($signature_param, $token, $secret_word, 'add_invoice_return');

		return $validSignature == $signature;
	}
	
	// Функция генерации и проверки цифровой подписи для уведомлений
	function computeSignature($json, $secretWord)
	{
		$hash = NULL;

		if (empty(trim($secretWord)))
			$hash = strtoupper(hash_hmac('sha1', $json, ""));
		else
			$hash = strtoupper(hash_hmac('sha1', $json, $secretWord));
		return $hash;
	}
}
