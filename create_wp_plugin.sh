#!/bin/sh

rm -fr wp-plugin/

mkdir wp-plugin/
mkdir wp-plugin/trunk
mkdir wp-plugin/assets
mkdir wp-plugin/branches
mkdir wp-plugin/tags

cp -r wp-assets/* wp-plugin/assets
mv wp-plugin/assets/readme.txt wp-plugin/trunk/.
mv wp-plugin/assets/changelog.txt wp-plugin/trunk/.

cp -r lib wp-plugin/trunk
cp -r src wp-plugin/trunk
cp -r tests wp-plugin/trunk
cp -r view wp-plugin/trunk
cp bolt-bigcommerce-wordpress.php wp-plugin/trunk

echo "\n/wp-plugin/ created\n"