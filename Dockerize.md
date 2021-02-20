# Dockerizing the Service Wrapper

These instructions provided by Nomad84

+ Create a new directory for metube related files
```
WEBOS-METUBE=<Path to your metube directory>
mkdir $WEBOS-METUBE
cd $WEBOS-METUBE
```

+ Clone the metube wrapper from git
    + `git clone https://github.com/codepoet80/metube-php-servicewrapper`

+ Create a config file based on this:
    + [https://raw.githubusercontent.com/h8pewou/legacy_webos/main/metube-webos-docker-compose.yml](https://raw.githubusercontent.com/h8pewou/legacy_webos/main/metube-webos-docker-compose.yml)
+ You will have to change the values in the config file! Obtain a your Youtube API key from Google.
    + `nano metube-php-servicewrapper/config.php`

+ Create your docker-compose.yaml based on this:
    + [https://raw.githubusercontent.com/h8pewou/legacy_webos/main/metube-webos-docker-compose.yml](https://raw.githubusercontent.com/h8pewou/legacy_webos/main/metube-webos-docker-compose.yml)
+ This works without any modifications but it only contains the bare minimum configuration.
    + `nano docker-compose.yml`

+ Bring up your docker containers
    + `docker-compose --file docker-compose.yml --project-name metube_webos up --detach`

+ Check if everything came up fine:
    + `docker ps`

+ Logs are available here:
    + `docker logs -f metube_webos_service_1`
    + `docker logs -f metube_webos_wrapper_1`

+ Next step: setup a clean-up solution for $WEBOS-METUBE/downloads
    + Example clean-up script is available at $WEBOS-METUBE/metube-php-servicewrapper/youtube-cleanup.sh 