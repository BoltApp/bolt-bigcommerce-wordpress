#!/usr/bin/env bash

red=`tput setaf 1`
green=`tput setaf 2`
reset=`tput sgr0`

# check if report folder exist.
if [ -d /home/travis/build/BoltApp/bolt-bigcommerce/var/report/ ]
then
    echo "${red} ### Reports folder present ### ${reset}"
    cd /home/travis/build/BoltApp/bolt-bigcommerce/var/report/
    for file in ./*; do
        cat "$(basename "$file")"
        echo "\n"
    done
else
    echo "${green} ### Reports folder not present ### ${reset}"
fi