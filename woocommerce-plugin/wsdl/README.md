# Elta Courier WSDL Files

This directory should contain the WSDL files downloaded from Elta Courier's FTP server.

## Why WSDL Files Are Required

**IMPORTANT:** Elta's API uses **SOAP**, not REST. PHP's `SoapClient` requires a WSDL (Web Services Description Language) file to:

- Understand the SOAP endpoint URL
- Know which methods are available (e.g., `READ`)
- Understand request/response field names (e.g., `WPEL_CODE`, `WPEL_USER`, `WPEL_VG`)
- Know data types and structure

**You cannot use Elta's tracking API without the WSDL file**, even if you only want to check the status of an existing voucher. The WSDL is the "contract" that defines how to communicate with Elta's SOAP service.

## FTP Access

**FTP Server:** `ftp.elta-courier.gr`  
**Port:** `21`  
**Username:** `wspel`  
**Password:** `wspel`

## Required WSDL Files

Download the following files from the FTP server and place them in this directory:

### Required for Tracking:
- **PELTT03.WSDL** - Shipping Status Web Service (Track & Trace) - **REQUIRED** for `get_voucher_status()` and `track_shipment()`

### Optional:
- **PELB64VG.WSDL** - Printing Label Web Service (for label generation)
- **GETPUDODETAILS.WSDL** - PUDO Stations Web Service (for PUDO lookup)
- **ELTACOURIERPOSTSIDETA.WSDL** - Post voucher details (for posting to existing vouchers)

**Note:** `CREATEAWB02.WSDL` is not needed - this plugin does not create vouchers. Vouchers come from order meta keys via other plugins.

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

## What Happens If WSDL Is Missing?

If a WSDL file is missing:
- The code will attempt to use a URL fallback (which typically won't work as Elta doesn't host WSDL files publicly)
- You'll get a clear error message indicating which WSDL file is missing
- The error will include instructions on where to place the file

## Note

This plugin **only reads tracking information** for existing vouchers. It does **not create** vouchers - vouchers come from order meta keys via other plugins (like Elta's own WooCommerce plugin).

