=== Event Tickets with Ticket Scanner ===
Contributors: sasonikolov
Tags: event ticketing, party tickets, ticket scanner, redeem tickets, woocommerce
Requires PHP: 7.0
Stable tag: 2.7.3
Tested up to: 6.8
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Selling event or party tickets, member entrance with any expiration date, multipass tickets, family tickets and more. Selling different kind of tickets and redeeming them was never so easy.
Sell tickets for your event or party with WooCommerce. Scan the tickets at the entrance. Optional design your own ticket look and feel.
You can scan the tickets of your customer from the QR code on their mobile devices or the printed PDF ticket.

== Description ==
**Sell, Scan, Succeed – Event Ticketing Made Easy**

Easily sell digital tickets for events, clubs, or memberships with the **Event Tickets with Ticket Scanner** plugin for WooCommerce. Turn any product into a scannable ticket with a unique QR code and downloadable PDF – ready to redeem at the entrance.

https://youtu.be/uWSdKdOyn70

[Whats in for you](https://youtu.be/KKLp1Lwqj_U)

**Perfect for:**
* Concerts, parties, and festivals
* Spas, clubs, gyms, theme parks
* Community events and memberships

**Key Benefits:**
* Built-in **browser-based ticket scanner** (mobile ready)
* Design your own ticket & badge with logo, banner & background
* Sell multi-entry, family, and time-limited tickets
* Supports WooCommerce variants and product bundles
* Offline fallback options available for big events

https://youtu.be/KKLp1Lwqj_U

A Quick start is shown in this [Quick start video](https://vollstart.com/event-tickets-with-ticket-scanner/docs/#quickstart).


**Flexible Setup:**
* Automatically generate secure ticket numbers or import your own
* **Free version:** Email includes download link to ticket PDF and (optional) QR code with ticket number
* **Premium only:** Attach ticket as PDF file directly to email
* Show ticket detail page with QR code and PDF download
* Use webhooks to notify third-party systems on redeem
* Built-in protection against fake tickets or double redemption

**Advanced Features (Premium):**
* PDF ticket file as attachment in order email
* Team scanner access via Auth Tokens
* Calendar invites (ICS files)
* Custom flyers and multipage PDF options
* CVV check and brute-force IP block
* Shortcodes for displaying and validating ticket numbers

**Get Started in 3 Steps:**
1. Install the plugin
2. Create a ticket list under “Event Tickets”
3. Enable “Ticket Sales” in your WooCommerce product

Customers get a unique ticket number, QR code, and download link. Redeem tickets via QR scanner or input field.

**Try it now for free.** Upgrade to Premium for high-volume event features and PDF delivery control.

== Links ==

* [Quickstart video](https://youtu.be/KKLp1Lwqj_U)
* [Documentation & Premium](https://vollstart.com/event-tickets-with-ticket-scanner)
* [Support](mailto:support@vollstart.com)
* [Event Tickets with WooCommerce Premium](https://vollstart.com/event-tickets-with-ticket-scanner/)

== Features ==
Here you can find all available options listed: [Display all options]](https://vollstart.com/event-tickets-with-ticket-scanner/docs/event-tickets-with-ticket-scanner-feature-list/)

https://youtu.be/ls_Lkf08n9I

* Sell event tickets as PDF with WooCommerce
* Single entrance, mulitpass, family pass, member card with expiration date and more
* Add QR, tickets, badges, additional PDF pages to the purchase order emails.
* Download PDF of ticket to print it as a badge for your customers
* Attach your own PDF to the ticket PDF (will be added as additional pages)
* Also for professional usage - use your QR and barcode scanner to verify the tickets
* WooCommerce product variants supported
* Generate flyer for your party or event
* Redeem event tickets at the entrance using the included ticket scanner page (mobile ready)
* Add ICS calendar file or a ticket to the purhcase email and ticket detail page
* Store WooCommerce orderid, itemid and productid to a ticket that was generated or used for a product sale
* You can now set a unique ticket number format for all WooCommerce product that are using a ticket number
* You can now set the ticket number format directly also on the WooCommerce product detail page if needed
* Use your codes to restrict purchases that allow a purchase of this product only if the buyer has a code for it (purchase allowance code)
* Add your own messages for the ticket number validation form for your customers
* Add your own message for the "product stolen" validation message
* Disable the validation form for not logged in wordpress user
* User can register to a ticket (with the wordpress user id if needed) after the ticket number is checked - this makes your code one-time usable
* Display registered user information of a ticket number during the validation if you need this
* One time check can have a maximum check amount based on ticket list or based on the global settings
* The user can be forwared (redirected) to an URL after the ticket number was checked - to show more details
* Webhooks - you can inform other systems about ticket redeemed status and validation steps
* Display assigned tickets to your user with a shortcode [sasoEventTickets_code]
* Add images to the ticket (header, background and Footer)
* Adopt font size for the PDF ticket
* Forcing responsive design for the ticket scanner for better experience
* Allow multiple redeem times for multi usage tickets
* Ticket badge designer for maximum control of the look and feel

== Technical Requirements ==
Wordpress, Woocommerce, php-curl, php-imagick

== GETTING STARTED ==

A Quick start is shown in this [video](https://vollstart.com/event-tickets-with-ticket-scanner/docs/#quickstart).
A good first start is to open the event ticket admin area and create a list first, if not already done or if you do not want to use the default ticket list.
Go to your WooCommerce product and activate the ticket sale option and set the list.
Check out all the possible options in the event ticket admin area to understand, how to tweak your usages of plugin.
Optional: Then add your ticket number by importing (add button at the ticket table) or assign the ticket list to your products.
Optional: If you need a validation form for your users, to check the ticket number, then please add the shortcode **[sasoEventTicketsValidator]** to a page.

= Steps to start =
- Go to the admin area and click on menu "Event Tickets".
- Click on button "Add" next to the heading "List".
- Go to your "ticket" product and set the option with in the product settings "Event Tickets"
*To scan the QR code of the sold tickets at the entrance:*
- Go to the admin area and click on menu "Event Tickets".
- Click on the button "Ticket Scanner" at the top area
- Scan tickets and redeem them

Try it out first, before you go Premium! ["Here you can find the premium plugin"](https://vollstart.com/event-tickets-with-ticket-scanner).

== Woocommerce support for auto-generating tickets ==
**Supports version 6+**
**You can use this plugin to auto-generate tickets and codes for your woocommerce products**
* Create a code list
* Go to your WooCommerce product and edit the product which should receive a ticket
* Go to your WooCommerce product and edit the product which should receive a code - if needed
* Click on "Event Tickets" in the attribute area of your product
* Choose the "List" that will be used for this product

Everytime this product is sold, it will get a new generated ticket number/code or use an unused one within your list (This need to be activated within the option settings). The new code will be added to the code list you set on your WooCommerce product and to the product sale too.
If the sold product quantity in the order is more than 1, then a ticket number/code will be generated for each element. The code will be generated after the purchase. In case of a refund the code will be recovered and marked as unused, so that it can be reused.

*E.g.: Your customer bought 2 of the same product within one order, then 2 tickets will be generated and stored to the product item within this order.*

= WooCommerce Ticket Features =
* Automatically create and assign tickets for physical products and digital products
* Recover tickets assigned to refunded orders
* Option to reuse the recovered tickets with the latest orders
* Automatically deliver the tickets with the complete order email
* Automatically a ticket as PDF for download and add the download link to the complete order email.
* Automatically deliver the tickets and download URL with the optional PDF invoice "WooCommerce PDF Invoices"
* Download a flyer for your event or party

= WooCommerce Code Features =
* Automatically create and assign codes for physical products and digital products
* Recover code assigned to refunded orders
* Option to reuse the recovered codes with the latest orders
* Automatically deliver the codes with the complete order email
* Automatically deliver the codes with the optional PDF invoice "WooCommerce PDF Invoices"

**Please note:**
If you exceed your limit (*no limits for premium user*) of the amount of possible tickets/codes, then the ticket/code added to the sold product will be a text information: **"Please contact our support for the code"**.
This way your business is not harmed and your customer can contact you to get a code manually. The format of the code will be **12345-12345-12345-12345** if you do not set a generation format within the options.

== WooCommerce PDF Invoices support to display the ticket numbers on the PDFs ==
If you use ["WooCommerce PDF Invoices"](https://en-gb.wordpress.org/plugins/woocommerce-pdf-invoices-packing-slips/), then the generated ticket numbers/codes are displayed on the generated PDFs too!
The supported PDF plugin is from Ewout Fernhout.

== WooCommerce Ticket Sale ==
You can add a list to your product and sell tickets. The ticket will be added to the sale informations for you and your client.
The client will also have a link to check the ticket and mark the ticket as used, only if the order is set to completed. This will mark the ticket as redeemed.
You can check the entrance by letting your customer show the confirmation page and hit on the "redeem"-button.
Or you scan the QR code of the ticket with the ticket scanner (included). The ticket is also available as PDF for download to your customers.
[Checkout the video, how it works](https://vollstart.com/event-tickets-with-ticket-scanner/docs/#ticket)

== Frontend ==
We have different frontend elements. Just to sell tickets and scan them, you do not need to add any shortcodes to your pages.

= Frontend event tickets =
Your customer will receive a specific URL to the ticket detail page. You can control which information to display. additional they can download the ticket as a PDF.
The ticket will contain a QR code, that can be scanned by you or your team (no loggin to WordPress needed) and redeem the ticket.
[Watch the video for it](https://vollstart.com/event-tickets-with-ticket-scanner/docs/#quickstart)

= Frontend event list
You can use the shortcode [sasoEventTicketsValidator_eventsview] to display the upcoming events. Default is to start for the whole month and the next 2 months.
You can add the parameter months_to_show to control how many months you want to show. Eg. months_to_show="3"

= Frontent to validate the ticket number =
* Use the shortcode **[sasoEventTicketsValidator]**
* Create a page or use an existing one and add the shortcode to the page
* The shortcode will be replaced for your users by a form to enter the ticket number and a button to validate the ticket. This allows you to surround the form with your own heading and instruction.
* Each ticket number has a display version (e.g. XYZXYZ -> XYZ-XYZ), so it is easier for your user to read the ticket number.
* The check will remove the display delimiter "-", ":", " " for the check automatically.
* So your user can enter the ticket number with or without delimiters.

If you use CVV on a code and the user enter the ticket number that requires a cvv, then your user will be ask to enter the CVV.
The user could enter the CVV immediately with the code. Separate the value with a ":". E.g: XYZXYZ:1234.

It is possible to prefill the ticket number validation form with a ticket number.
Add the parameter "code" to your page URL to create a link that prefills the form.
*E.g https://vollstart.com/serial-codes/?code=123-456-789*

= Form options for expert =
You can use your own input, trigger and output HTML element.
Add the id parameter to your HTML elements and pass them to the shortcode as corresponding parameter.
You can add also your own JS function name that will be called before the ticket number is checked on the server and also if the result comes back.
*[sasoEventTicketsValidator inputid="" triggerid="" outputid="" jspre="" jsafter=""]*
[Read here more about this feature](https://vollstart.com/event-tickets-with-ticket-scanner/docs/#styling)

== Quick overview ==
Each ticket number is unique. The list is for your organisation and for your WooCommerce products.

= Plugin administration - where to find the plugin management area =
*It will add a new menu entry "Event Tickets" within the settings section.*

[More about the plugin on our website](https://vollstart.com/event-tickets-with-ticket-scanner/docs)

== Support ==
Write to support@vollstart.com for support request.
For both plugins: The basic free and for the premium plugin.
We are here to help you.

== Installation ==

* WordPress 5.0 or greater
* PHP version 7.0 or greater
* MySQL version 5.0 or greater

= Installation =

1. Install the pluging using the WordPress built-in Plugin installer.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Click on the menu "Event Tickets" and browse the options - optional.
4. Edit your product to generate a event ticket.

= Quick Setup =
This plugin extend WooCommerce to be able to setup your product as a ticket. Go to the product. Open the Event Tickets tab and activate the checkbox. Set the ticket list and fill out the other details if needed.
The default is to generate the ticket if the order is "completed". For automatically order status set to "completed" set up the ticket as a virtual product and/or download product - add a flyer or so as a download.
To test the ticket. Create an order within the order backend, set the order status to "completed" - this will assign the ticket numbers. Check the order email. Click on the ticket detail link to check the ticket detail page.
Create a real test purchase (with coupon code or wire transfer), check the order email.
If you have a 404 page for ticket detail page or ticket scanner page, then set up the compatibility options.

**For more help and your first steps, please [visit our website](https://vollstart.com/event-tickets-with-ticket-scanner/docs/)**

== Plugin support ==
* WPML plugin
* PDF Invoices & Packing Slips for WooCommerce

== Screenshots ==

1. **Ticket Details Mobile** The ticket details are also optimized for mobile devices.
2. **Ticket Details Desktop** You can define what will be shown on the ticket.
3. **Ticket PDF** Your customer can download the ticket as a PDF.
4. **Ticket scanner** Scan and redeem the tickets at the entrance on mobile and desktop devices.
5. **Ticket Badge** Print out your ticket badge with customer name on it.
6. **Options 1** Ticket options.
7. **Options 3** Created tickets backend admin area.
8. **Options 4** Ticket numbers can be pre generated if needed.
9. **Flyer example** You can also adjust your event or party flyer.
10. **Ticket example** You can adjust different areas of the PDF ticket.
11. **Product settings** You overwrite the format of the ticket number and activate the ticket sale.
12. **Options 5** Adjust the information on the flyer.
13. **Order Ticket Detail View** Quick ticket scan possible with the order ticket detail view.

== Upgrade Notice ==
= 2.0.5 =
Attention, the option wcTicketShowRedeemBtnOnTicket is added and replace the old wcTicketDontShowRedeemBtnOnTicket.
The template for the ticket designer is adjusted for this!
In the future the template code part with the buttons will be removed from the ticket designer.

= 1.3.0 =
Attention, the translation forced bigger chunk of code to be changed.

= 1.2.0 =
To update the old sold tickets, please execute the "repair table" button within the support area. From now on we will store also the user id of the ticket purchase.

= 1.0.11 =
Default value is changed to true for the option ro reuse not used tickeu39974   t numbers within a ticket list assigned to a product

= 1.0.9 =
New option to add the amount of purchased tickets per sold item on the PDF. Database updated. You can see now the redeemed ticket information within the admin area on the data table.

= 1.0.5 =
Serial code options are removed. They do not fit with the event and party tickets approach.

= 1.0.4 =
Activate in the options the new feature to attach the calendar entry (ICS file) to your purchase emails.

= 2.4.0 =
New Javascript library for the ticket scanner. If you need to use the old ticket scanner, then add the parameter &useoldticketscanner=1 to the ticket scanner URL.

= 2.4.1 =
New default value for the option to allow access to the admin area of the tickets - now it is false, only administrator, until you set it otherwise.

= 2.5.0 =
Default ticket template was adjusted. Plugin tested with PHP 8.3 - to use URLs in your template for the PDF make sure you have php8.3-curl and php8.3-imagick installed.

== Changelog ==
= 2.7.3 - 2025-06-24 =
* Fix for missing parent product id with wpml within the tickets
* Added languages for ticket scanner (ch_CN, es_ES, fr_FR, it_IT, ja_JP, nl_NL. pt_BR, pt_PT)

= 2.7.2 - 2025-06-17 =
* Fix qr content on ticket badge

= 2.7.1 - 2025-06-12 =
* Fix ticket scanner - it was prepared by accident to have a bit of code that is new approach.

= 2.7.0 - 2025-06-11 =
* Fix removing the ticket numbers from the order item, even if the tickets are already deleted.
* Changed the premium license link.
* Fix wrong QR code on QR code image and PDF if you use your own qr code content with option qrOwnQRContent.
* Fix wrong QR code on ticket badge if you use your own qr code content with option qrOwnQRContent.

= 2.6.11 - 2025-05-28 =
* Fix PDF ob_clean notice.
* Fix wrong version number.
* Fix cart (datepicker, text-value, option-value) value saving within the cart view and checkout.
* Fix update all dates on the cart view and checkout.

= 2.6.10 - 2025-05-26 =
* Add shortcode to display all options/features.
* Added ticket title and sub title (variation name) above the buttons for the distract free view of the scanned ticket.
* Display last redeem operation with the redeem information on the ticket scanner.
* Add redeem button to the top for fast redeem operation after the ticket is retrieved on the ticket scanner.
* New option to preset the ticket scanner to display the short description in distract free mode - ticketScannerHideTicketInformationShowShortDesc
* Bug fix for missing orders if the order will be checked for "is-paid"

= 2.6.9 = 2025-05-19 =
* Fix date picker saving value at cart.
* Reinit the date picker fields if the cart was updated.

= 2.6.8 - 2025-05-13 =
* Fix issue with variable products on the order view.

= 2.6.7 - 2025-05-13 =
* Add is_daychooser and day_per_ticket to the export. You have the meta information now direct as a column: meta_wc_ticket_is_daychooser, meta_wc_ticket_day_per_ticket.
* Add new filter for ticket numbers in the admin to search for a choosen date on the tickets. Search value is DAYPERTICKET:YYYY-MM-DD. You can also search for YYYY-MM or just YYYY.
* You can now add and & to the search within the ticket admin to search for more than one filter. But limited to filters and one normal search. E.g. PRODUCTID:123 & ORDERID:123 & ticketnumber.
* You can cownload the ticket badges from within the order now

= 2.6.6 - 2025-05-06 =
* Added more help videos to the options

= 2.6.5 - 2025-05-05 =
* Added list of third party libraries to the support information area.
* Ticket scanner has a new option to use the old QR code scanner library for compatibility mode in case your iphone is not working as expected.
* Admin area is refreshing the security code (nonce) automatically if open.
* Add an newline to put the date on the next line in the order items table.
* Thank you page uses now the new WooCommerce hook.
* Added font for PDF Roboto and Newsreader to support more languages.

= 2.6.4 - 2025-04-24 =
* Basic WPML plugin support added.
* Optimized the plugin speed a little bit.

= 2.6.3 - 2025-04-16=
* Datatables in the admin made width=100%.
* Add checks for used 3rd party php classes, so that they will not be re-added to php - what could cause conflicts.
* Display customer name is now using $order->get_formatted_billing_full_name.

= 2.6.2 - 2025-04-07 =
* Two columns added to the export: is_daychooser and day_per_ticket.
* Preparation to display the redeemed and not redeemed tickets at the ticket scanner - with premium 1.5.2 available.
* Changing title of the admin area from Event Tickets with Woocommerce to Event Tickets with Ticket Scanner
* Fixed the default value for Date2Text Javascript function on the ticket.
* Ticket scanner is now checking at least every 4 minutes for a new nonce security token, to prevent access error message.
* Fix for the date chooser - it will prevent allowing to choose a date before today.

= 2.6.1 - 2025-03-18 =
* Bug fix new check of the option value is active

= 2.6.0 - 2025-03-17 =
* Bug fix for unchecking of product checkboxes in the event tickets tab
* New shortcode [sasoEventTicketsValidator_eventsview]. Add event calendar view for events with an start date. Missing end date will be treated like same day.
* Option wcassignmentDoNotPutOnEmail re-added :)
* Option wcassignmentDoNotPutOnPDF re-added :)
* Small optimizations on options call and find products to make them private after expired
* Format for date and time is also passed to the outputs
* Ticket scanner is showing now always the variant name at the top - even if the option is deactivated. For the ticket view and PDF it is still the same.
* Ticket scanner text input field for hardware qr code scanner excepts also "'" as divider of the public ticket number.
