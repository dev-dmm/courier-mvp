# Elta Courier WSDL Files

This directory should contain the WSDL files downloaded from Elta Courier's FTP server.

## FTP Access

**FTP Server:** `ftp.elta-courier.gr`  
**Port:** `21`  
**Username:** `wspel`  
**Password:** `wspel`

## Required WSDL Files

Download the following files from the FTP server and place them in this directory:

1. **CREATEAWB02.WSDL** - Voucher Creation Web Service
2. **ELTACOURIERPOSTSIDETA.WSDL** - Voucher Production Web Service (POST)
3. **PELTT03.WSDL** - Shipping Status Web Service (Track & Trace)
4. **PELB64VG.WSDL** - Printing Label Web Service
5. **GETPUDODETAILS.WSDL** - PUDO Stations Web Service

## How to Download

You can use an FTP client (like FileZilla) or command line:

```bash
# Using command line FTP
ftp ftp.elta-courier.gr
# Username: wspel
# Password: wspel
# Then download the WSDL files
```

Or download via your FTP client and place them in this `wsdl/` directory.

## Note

If WSDL files are not found locally, the plugin will attempt to use URLs, but this may not work reliably. It's recommended to download and use local WSDL files.

