# Provenance de la baseline plugin v0.1.57

Cette note est volontairement séparée du commit d’import initial `1772305e7c830b975421e744b7ab8b6b8333e569`, lequel contient **uniquement** les fichiers récupérés à l’identique depuis l’archive originale.

| Élément | Valeur vérifiée |
|---|---|
| Dépôt source de l’artefact | `hossin00/woocommerce-whop-saas` |
| Branche Git contenant l’artefact | `origin/feat/commercial-site-v1` |
| Commit qui ajoute l’artefact | `a570687e58ee533a3ecb6b0c9ac6f215a21975dc` — `fix: add v0.1.57 silent bank rejection artifact` |
| Chemin Git de l’artefact | `private-assets/whop-woocommerce-plugin-v0.1.57.zip` |
| Objet blob Git | `0128a0a9686bede333630d4021cb4cd74d800440` |
| Taille Git | 15 113 450 octets |
| Nom original | `whop-woocommerce-plugin-v0.1.57.zip` |
| SHA-256 de l’archive originale | `87cc3f389e305bad75ca9ffafd4a8d358a2a5e8ad1d68bf1dd980315239de9b2` |
| Intégrité ZIP | `unzip -t` : PASS, aucune erreur de données compressées |
| Répertoire racine ZIP | `whop-woocommerce-plugin/` |
| Nombre de membres ZIP | 113 |
| Commit d’import Git local | `1772305e7c830b975421e744b7ab8b6b8333e569` sur `recovery/plugin-v0.1.57-source` |
| Vérification d’import | Chaque fichier régulier importé a été comparé par SHA-256 à son membre ZIP avant le commit. |
| Fichier bootstrap | `whop-woocommerce.php` |
| Version en-tête WordPress | `0.1.57` |
| Constante interne | `WHOP_WOOCOMMERCE_VERSION = '0.1.57'` |
| PHP minimum | `8.2` |
| WordPress minimum | `6.0` |
| WooCommerce minimum | `8.0` |
| Métadonnées Composer | `whop/woocommerce-checkout`, type `wordpress-plugin`, autoload PSR-4 `Whop\\WooCommerce\\` → `includes/` |
| État runtime | `vendor/autoload.php` et les fichiers `vendor/composer/*` sont présents dans l’archive et dans le baseline récupéré. |

Le manifeste exact des membres de l’archive est conservé dans `provenance/v0.1.57-file-manifest.txt`.

> Cette baseline est une récupération de source à partir d’un artefact versionné. Elle n’est ni une publication commerciale, ni une autorisation de déployer le plugin, ni une modification de Production.
