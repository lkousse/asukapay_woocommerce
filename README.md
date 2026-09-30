# AsukaPay pour WooCommerce

Extension WordPress/WooCommerce officielle pour encaisser via [AsukaPay](https://asukapay.com) — Mobile Money (MTN, Moov, Orange, Wave, Coris, Free, Togocom…) et carte bancaire en Afrique de l'Ouest et du Centre.

Documentation complète : **[docs.asukapay.com/woocommerce.html](https://docs.asukapay.com/woocommerce.html)**.

## Installation

Télécharger le zip de la [dernière version](https://github.com/lkousse/asukapay_woocommerce/releases/latest/download/asukapay-for-woocommerce.zip), puis dans WordPress :

**Extensions → Ajouter → Téléverser une extension**, sélectionner le zip, **Installer maintenant**, **Activer**.

Ensuite `WooCommerce → Réglages → Paiements → AsukaPay` pour renseigner les clés API.

## Points clés

- Mobile Money collecté **directement dans le tunnel de commande** — pas de redirection vers une page hébergée. Le champ OTP n'apparaît que pour les opérateurs qui l'exigent (`coris`, `orange_bf`, `wave_bf`, `orange_sn`), détecté en direct via `GET /payment-modes`.
- Carte bancaire : redirection vers la page hébergée renvoyée par l'API (incontournable pour la capture des données bancaires).
- Webhook enregistré automatiquement auprès d'AsukaPay à chaque sauvegarde des réglages — rien à recopier dans le tableau de bord.
- Remboursements déclenchés depuis WooCommerce, relayés à l'API.

## Développement

Le code source est dans [`asukapay-for-woocommerce/`](asukapay-for-woocommerce). Voir [`readme.txt`](asukapay-for-woocommerce/readme.txt) pour le format WordPress.org standard (description, FAQ, changelog).
