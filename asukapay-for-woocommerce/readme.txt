=== AsukaPay pour WooCommerce ===
Contributors: asukapay
Tags: woocommerce, mobile money, paiement, afrique, mtn, orange, wave, moov
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Encaissez Mobile Money et carte bancaire directement dans le tunnel de commande WooCommerce, via AsukaPay.

== Description ==

AsukaPay pour WooCommerce ajoute un moyen de paiement Mobile Money (MTN, Moov, Orange, Wave, Coris, Free, Togocom…) et carte bancaire (Visa, Mastercard) à votre boutique, en s'appuyant sur l'API publique AsukaPay (api.asukapay.com/api/v1).

Contrairement à une intégration par redirection, le numéro de téléphone Mobile Money est collecté directement dans le tunnel de commande WooCommerce — le client ne quitte pas votre boutique. Les quelques opérateurs qui exigent un code USSD (Coris, Orange Burkina Faso, Wave Burkina Faso, Orange Sénégal) affichent automatiquement un champ OTP. Le paiement par carte redirige vers la page hébergée sécurisée renvoyée par l'API, comme pour toute passerelle carte.

= Fonctionnalités =

* Catalogue d'opérateurs à jour en temps réel (appel `GET /payment-modes`, en cache 1 h) — jamais figé en dur dans le plugin.
* Détection automatique des opérateurs à OTP via le champ `requires_otp` de l'API.
* Webhooks : l'URL de notification est enregistrée automatiquement auprès d'AsukaPay à chaque sauvegarde des réglages, signature HMAC SHA-256 vérifiée sur le corps brut.
* Remboursements déclenchés depuis WooCommerce (Commandes → Rembourser), relayés à `POST /payments/{reference}/refund`.
* Environnements production et test entièrement séparés (deux paires de clés, deux secrets de webhook).

= Prérequis =

* Un compte marchand AsukaPay (https://app.asukapay.com/signup) et ses clés API (tableau de bord → Développeurs).
* WooCommerce actif.
* Boutique servie en HTTPS (obligatoire pour recevoir les webhooks).

== Installation ==

1. Copiez le dossier `asukapay-for-woocommerce` dans `wp-content/plugins/`, ou téléversez le zip depuis Extensions → Ajouter.
2. Activez le plugin.
3. Allez dans WooCommerce → Réglages → Paiements → AsukaPay.
4. Renseignez vos clés secrètes (production et/ou test), activez le moyen de paiement.
5. Enregistrez : le plugin déclare automatiquement l'URL de webhook auprès d'AsukaPay pour chaque clé renseignée.

== Frequently Asked Questions ==

= Le client doit-il quitter ma boutique pour payer en Mobile Money ? =

Non. Le numéro (et le code OTP si l'opérateur l'exige) sont saisis directement dans le tunnel de commande WooCommerce. Seul le paiement par carte redirige vers une page hébergée, ce qui est incontournable pour la capture des données bancaires.

= Comment sont confirmées les commandes Mobile Money ? =

Le paiement Mobile Money est asynchrone : la commande passe « En attente » puis « Payée » dès réception du webhook `payment.succeeded` d'AsukaPay. Le client valide le débit sur son téléphone pendant ce temps.

= Le plugin fonctionne-t-il avec HPOS (stockage haute performance des commandes) ? =

Oui, la compatibilité est déclarée explicitement.

== Changelog ==

= 1.0.0 =
* Version initiale : paiement Mobile Money dans le tunnel, carte via page hébergée, webhooks signés, remboursements.
