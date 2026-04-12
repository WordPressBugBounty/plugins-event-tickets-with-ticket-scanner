<?php
/**
 * WooCommerce Email Attachment Handler
 *
 * Handles attachment of ticket-related files (PDFs, QR codes, ICS files) to WooCommerce emails.
 *
 * @package    Event_Tickets_With_Ticket_Scanner
 * @subpackage WooCommerce
 * @since      2.9.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Email Attachment Handler Class
 *
 * Manages email attachments for WooCommerce transactional emails including:
 * - ICS calendar files for event tickets
 * - PDF ticket badges
 * - QR code images and PDFs
 * - Temporary file management and cleanup
 *
 * @since 2.9.0
 */
if (!class_exists('sasoEventtickets_WC_Email')) {
	class sasoEventtickets_WC_Email extends sasoEventtickets_WC_Base {

		/**
		 * Temporary attachments array for cleanup
		 *
		 * @var array
		 */
		private $_attachments = [];

		/**
		 * Constructor
		 *
		 * @param sasoEventtickets $main Main plugin instance
		 */
		public function __construct($main) {
			parent::__construct($main);
		}

		/**
		 * Attach ticket files to WooCommerce emails
		 *
		 * Generates and attaches various ticket-related files to customer emails:
		 * - ICS calendar files (for events with dates)
		 * - PDF badge files
		 * - QR code images/PDFs
		 *
		 * @param array $attachments Existing email attachments
		 * @param string $email_id WooCommerce email ID
		 * @param WC_Order $order Order object
		 * @return array Modified attachments array
		 */
		public function woocommerce_email_attachments($attachments, $email_id, $order) {
			if (!is_a($order, 'WC_Order') || !isset($email_id)) {
				return $attachments;
			}

			$this->_attachments = [];

			// Attach ICS calendar files
			$this->attachICSFiles($email_id, $order);

			// Attach badge PDFs
			$this->attachBadgePDFs($email_id, $order);

			// Attach QR codes
			$this->attachQRCodes($email_id, $order);

			// Apply filters for extensibility
			$_attachments = apply_filters($this->MAIN->_add_filter_prefix . 'woocommerce_email_attachments', $attachments, $email_id, $order);
			if (count($_attachments) > 0) {
				$this->_attachments = array_merge($this->_attachments, $_attachments);
			}

			$_attachments = apply_filters($this->MAIN->_add_filter_prefix . 'woocommerce-hooks_woocommerce_email_attachments', $_attachments, $attachments, $email_id, $order);

			// Add files to attachments array
			foreach ($this->_attachments as $item) {
				if (is_string($item)) {
					if (file_exists($item)) {
						$attachments[] = $item;
					}
				}
			}

			// Register cleanup hooks with captured attachments to prevent file leaks
			// if multiple emails are sent in the same request
			if (count($this->_attachments) > 0) {
				// Capture current attachments in closure to avoid instance state issues
				$attachments_to_cleanup = $this->_attachments;

				// Use closures to capture the specific files for THIS email
				$cleanup_succeeded = function($mail_data) use ($attachments_to_cleanup) {
					$this->delete_specific_attachments($attachments_to_cleanup);
				};
				$cleanup_failed = function($wp_error) use ($attachments_to_cleanup) {
					$this->delete_specific_attachments($attachments_to_cleanup);
				};

				add_action('wp_mail_succeeded', $cleanup_succeeded, 10, 1);
				add_action('wp_mail_failed', $cleanup_failed, 10, 1);
			}

			return $attachments;
		}

		/**
		 * Attach ICS calendar files to email
		 *
		 * @param string $email_id WooCommerce email ID
		 * @param WC_Order $order Order object
		 * @return void
		 */
		private function attachICSFiles(string $email_id, $order): void {
			$wcTicketAttachICSToMail = $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketAttachICSToMail');
			if (!$wcTicketAttachICSToMail) {
				return;
			}

			// Only attach to specific email types
			$allowed_emails = ['customer_completed_order', 'customer_note', 'customer_invoice', 'customer_processing_order'];
			if (!in_array($email_id, $allowed_emails)) {
				return;
			}

			if (!class_exists("sasoEventtickets_Ticket")) {
				require_once(plugin_dir_path(dirname(dirname(__FILE__))) . "sasoEventtickets_Ticket.php");
			}

			$tickets = $this->MAIN->getWC()->getOrderManager()->getTicketsFromOrder($order);
			$dirname = $this->getTempDirectory();
			if (!$dirname) {
				return;
			}

			foreach ($tickets as $key => $ticket) {
				try {
					$product_id = $ticket["product_id"];
					$product = wc_get_product($product_id);
					$ticket_start_date = trim(get_post_meta($product_id, 'saso_eventtickets_ticket_start_date', true));

					if (!empty($ticket_start_date)) {
						$is_daychooser = get_post_meta($product_id, 'saso_eventtickets_is_daychooser', true) == "yes";

						if ($is_daychooser) {
							// Generate ICS file for each code (day chooser)
							$codes = !empty($ticket['codes']) ? explode(",", $ticket['codes']) : [];

							foreach ($codes as $code) {
								try {
									$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($code);
									if ($codeObj == null) {
										continue;
									}

									$contents = $this->MAIN->getTicketHandler()->generateICSFile($product, $codeObj);
									$file = $dirname . "ics_" . $product_id . "_" . $code . ".ics";
									file_put_contents($file, $contents);
									$this->_attachments[] = $file;
								} catch (Exception $e) {
									$this->MAIN->getAdmin()->logErrorToDB($e);
									continue;
								}
							}
						} else {
							// Generate single ICS file
							$contents = $this->MAIN->getTicketHandler()->generateICSFile($product);
							$file = $dirname . "ics_" . $product_id . ".ics";
							file_put_contents($file, $contents);
							$this->_attachments[] = $file;
						}
					}
				} catch (Exception $e) {
					$this->MAIN->getAdmin()->logErrorToDB($e);
				}
			}
		}

		/**
		 * Attach badge PDFs to email
		 *
		 * @param string $email_id WooCommerce email ID
		 * @param WC_Order $order Order object
		 * @return void
		 */
		private function attachBadgePDFs(string $email_id, $order): void {
			$wcTicketBadgeAttachFileToMail = $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketBadgeAttachFileToMail');
			if (!$wcTicketBadgeAttachFileToMail) {
				return;
			}

			$allowed_emails = $this->MAIN->getOptions()->get_wcTicketAttachTicketToMailOf();
			if (!in_array($email_id, $allowed_emails)) {
				return;
			}

			$badgeHandler = $this->MAIN->getTicketBadgeHandler();
			$tickets = $this->MAIN->getWC()->getOrderManager()->getTicketsFromOrder($order);

			if (count($tickets) == 0) {
				return;
			}

			$dirname = $this->getTempDirectory();
			if (!$dirname) {
				return;
			}

			$attachments_badges = [];

			foreach ($tickets as $key => $ticket) {
				try {
					$product_id = $ticket["product_id"];
					$codes = !empty($ticket['codes']) ? explode(",", $ticket['codes']) : [];

					foreach ($codes as $code) {
						try {
							$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($code);
						} catch (Exception $e) {
							continue;
						}
						$attachments_badges[] = $badgeHandler->getPDFTicketBadgeFilepath($codeObj, $dirname);
					}

					// Merge into one PDF if option is enabled
					$wcTicketBadgeAttachFileToMailAsOnePDF = $this->MAIN->getOptions()->getOptionValue("wcTicketBadgeAttachFileToMailAsOnePDF");
					if ($wcTicketBadgeAttachFileToMailAsOnePDF && count($attachments_badges) > 1) {
						$filename = "ticketbadges_" . $codeObj['order_id'] . ".pdf";
						$this->_attachments[] = $this->MAIN->getCore()->mergePDFs($attachments_badges, $filename, "F", false);
					} else {
						$this->_attachments = array_merge($this->_attachments, $attachments_badges);
					}
				} catch (Exception $e) {
					$this->MAIN->getAdmin()->logErrorToDB($e);
				}
			}
		}

		/**
		 * Attach QR codes to email
		 *
		 * @param string $email_id WooCommerce email ID
		 * @param WC_Order $order Order object
		 * @return void
		 */
		private function attachQRCodes(string $email_id, $order): void {
			$qrAttachQRImageToEmail = $this->MAIN->getOptions()->isOptionCheckboxActive('qrAttachQRImageToEmail');
			$qrAttachQRPdfToEmail = $this->MAIN->getOptions()->isOptionCheckboxActive('qrAttachQRPdfToEmail');

			if (!$qrAttachQRImageToEmail && !$qrAttachQRPdfToEmail) {
				return;
			}

			$allowed_emails = $this->MAIN->getOptions()->get_wcTicketAttachTicketToMailOf();
			if (!in_array($email_id, $allowed_emails)) {
				return;
			}

			$qrHandler = $this->MAIN->getTicketQRHandler();
			$tickets = $this->MAIN->getWC()->getOrderManager()->getTicketsFromOrder($order);

			if (count($tickets) == 0) {
				return;
			}

			$dirname = $this->getTempDirectory();
			if (!$dirname) {
				return;
			}

			$attachments_qrcodes_pdf = [];
			$attachments_qrcodes_images = [];

			foreach ($tickets as $key => $ticket) {
				try {
					$product_id = $ticket["product_id"];
					$codes = !empty($ticket['codes']) ? explode(",", $ticket['codes']) : [];

					foreach ($codes as $code) {
						try {
							$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($code);
						} catch (Exception $e) {
							continue;
						}

						$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
						$qr_content = $this->MAIN->getCore()->getQRCodeContent($codeObj, $metaObj);

						try {
							if ($qrAttachQRImageToEmail) {
								$attachments_qrcodes_images[] = $qrHandler->renderPNG($qr_content, "F");
							}
							if ($qrAttachQRPdfToEmail) {
								$attachments_qrcodes_pdf[] = $qrHandler->renderPDF($qr_content, "F");
							}
						} catch (Exception $e) {
							$this->MAIN->getAdmin()->logErrorToDB($e);
							continue;
						}
					}

					// Add images directly
					if (count($attachments_qrcodes_images) > 0) {
						$this->_attachments = array_merge($this->_attachments, $attachments_qrcodes_images);
					}

					// Merge PDFs into one if option is enabled
					$qrAttachQRFilesToMailAsOnePDF = $this->MAIN->getOptions()->getOptionValue("qrAttachQRFilesToMailAsOnePDF");
					if ($qrAttachQRFilesToMailAsOnePDF && count($attachments_qrcodes_pdf) > 1) {
						$filename = "ticketqrcodes_" . $codeObj['order_id'] . ".pdf";
						$this->_attachments[] = $this->MAIN->getCore()->mergePDFs($attachments_qrcodes_pdf, $filename, "F", false);
					} else {
						if (count($attachments_qrcodes_pdf) > 0) {
							$this->_attachments = array_merge($this->_attachments, $attachments_qrcodes_pdf);
						}
					}
				} catch (Exception $e) {
					$this->MAIN->getAdmin()->logErrorToDB($e);
				}
			}
		}

		/**
		 * Get temporary directory for file storage
		 *
		 * Creates directory if it doesn't exist.
		 *
		 * @return string|false Directory path or false if not writable
		 */
		private function getTempDirectory() {
			$dirname = get_temp_dir();

			if (!wp_is_writable($dirname)) {
				return false;
			}

			$dirname .= trailingslashit($this->MAIN->getPrefix());

			if (!file_exists($dirname)) {
				wp_mkdir_p($dirname);
			}

			return $dirname;
		}

		/**
		 * Delete specific temporary attachment files
		 *
		 * Removes a specific list of temporary files created for email attachments.
		 * Only deletes files in the plugin's temp directory for safety.
		 * This method is used by closure callbacks to clean up files for specific emails.
		 *
		 * @param array $attachments Array of file paths to delete
		 * @return void
		 */
		private function delete_specific_attachments(array $attachments): void {
			$dirname = get_temp_dir() . $this->MAIN->getPrefix();

			foreach ($attachments as $item) {
				try {
					if (file_exists($item) && dirname($item) == $dirname) {
						@unlink($item);
					}
				} catch (Exception $e) {
					$this->MAIN->getAdmin()->logErrorToDB($e);
				}
			}
		}

		/**
		 * Add ticket download links to order emails
		 *
		 * Displays download links for PDF tickets and order ticket view
		 * within allowed email types.
		 *
		 * @param WC_Order $order Order object
		 * @param bool $sent_to_admin Whether email is sent to admin
		 * @param bool $plain_text Whether email is plain text
		 * @param WC_Email $email Email object
		 * @return void
		 */
		public function woocommerce_email_order_meta($order, $sent_to_admin, $plain_text, $email): void {
			$allowed_emails = $this->MAIN->getOptions()->get_wcTicketAttachTicketToMailOf();
			if (!is_array($allowed_emails) || !in_array($email->id, $allowed_emails)) {
				return;
			}

			$isHeaderAdded = false;
			$hasTickets = false;

			// Display "Download All Tickets as PDF" button
			$wcTicketDisplayDownloadAllTicketsPDFButtonOnMail = $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDisplayDownloadAllTicketsPDFButtonOnMail');
			if ($wcTicketDisplayDownloadAllTicketsPDFButtonOnMail) {
				$hasTickets = $this->MAIN->getWC()->getOrderManager()->hasTicketsInOrderWithTicketnumber($order);
				if ($hasTickets) {
					$url = $this->MAIN->getCore()->getOrderTicketsURL($order);
					$dlnbtnlabel = $this->MAIN->getOptions()->getOptionValue('wcTicketLabelPDFDownload');
					$dlnbtnlabelHeading = trim($this->MAIN->getOptions()->getOptionValue('wcTicketLabelPDFDownloadHeading'));
					if (!empty($dlnbtnlabelHeading)) {
						echo '<h2>' . esc_html($dlnbtnlabelHeading) . '</h2>';
					}
					echo '<p><a target="_blank" href="' . esc_url($url) . '"><b>' . esc_html($dlnbtnlabel) . '</b></a></p>';
					$isHeaderAdded = true;
				}
			}

			// Display "View Order Tickets" link
			$wcTicketDisplayOrderTicketsViewLinkOnMail = $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDisplayOrderTicketsViewLinkOnMail');
			if ($wcTicketDisplayOrderTicketsViewLinkOnMail) {
				if ($hasTickets === false) {
					$hasTickets = $this->MAIN->getWC()->getOrderManager()->hasTicketsInOrderWithTicketnumber($order);
				}
				if ($hasTickets) {
					$url = $this->MAIN->getCore()->getOrderTicketsURL($order, "ordertickets-");
					$dlnbtnlabel = $this->MAIN->getOptions()->getOptionValue('wcTicketLabelOrderDetailView');
					if (!$isHeaderAdded) {
						$dlnbtnlabelHeading = trim($this->MAIN->getOptions()->getOptionValue('wcTicketLabelPDFDownloadHeading'));
						if (!empty($dlnbtnlabelHeading)) {
							echo '<h2>' . esc_html($dlnbtnlabelHeading) . '</h2>';
						}
					}
					echo '<p><a target="_blank" href="' . esc_url($url) . '"><b>' . esc_html($dlnbtnlabel) . '</b></a></p>';
				}
			}

			do_action($this->MAIN->_do_action_prefix . 'woocommerce-hooks_woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);
		}
	}
}
