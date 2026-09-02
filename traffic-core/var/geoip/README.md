# GeoDb binary data

`GeoDbResolver` (`src/Pipeline/GeoDb/GeoDbResolver.php`) reads
`IP2LOCATION-LITE-DB3.BIN` from this directory (override the path via the
`GEODB_IP2LOCATION_PATH` env var). The file isn't committed — ~48MB of
binary data, not source, and environment-specific like `vendor/`.

Get it from either:
- The legacy repo this project is replacing:
  `var/geoip/IP2Location/lite/IP2LOCATION-LITE-DB3.BIN`
- IP2Location's own free LITE download (DB3, IPv4, no registration
  required as of this writing): https://lite.ip2location.com/database/db3-ip-country-region-city

Place it at `traffic-core/var/geoip/IP2LOCATION-LITE-DB3.BIN`.

This is DB3 LITE specifically: country + region + city only. No ISP,
carrier, or connection-type data — that tier isn't available in the LITE
license (see `docs/PORTING_LOG.md`'s GeoDb finding for why `isp_id`/
`operator_id`/`connection_type_id` stay null everywhere in this project).
