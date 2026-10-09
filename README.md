# REDCap-API-Client

This REDCap module allows data to be sent to and received from an external API.

## System-level configuration options

### Allow normal users (non-administrators) to configure API connections

If enabled, anyone with design/setup rights in a project can configure API connections for that
project. If disabled, only administrators can configure API connections. This setting can be
overridden for individual projects.

Please be aware that the API client can make arbitrary connections to other servers directly from
your REDCap web server, as specified by the user. You should consider any security implications
before allowing non-administrators to configure API connections.

### Allow list of domains for API connections

List the domains (e.g. `api.example.com`), one per line, that the API client is allowed to connect
to. Subdomains are not automatically included and must be listed explicitly. If this setting is
blank then the API client can connect to all domains. This applies to all connection types.

If you are allowing non-administrators to use the API client to communicate with specific services,
it is recommended that you use this setting.

### Allow connections to IPv4 private network ranges and link-local addresses

By default, the API client will attempt to block connections to IPv4 private network ranges as
specified in RFC 1918 (10.\*, 172.16.\*-172.31.\*, 192.168.\*) and IPv4 link-local addresses
(169.254.\*). Enabling this setting will allow these connections.

Enabling this setting may have security implications if you allow non-administrators to configure
API connections, as it could allow access to protected internal resources.

### HTTP proxy host/port

Specify a HTTP proxy host (e.g. `proxy.example.com`) and port (e.g. `3128`) if required.

### File path of cURL CA bundle

If HTTP/REST connections to secure (https) endpoints fail, this may be because the cURL library does
not have a set of trusted certificate authorities. To fix this, obtain a file of trusted certificate
authority root certificates and place the file somewhere on the server. You can specify the location
of this file in the *curl.cainfo* setting in php.ini, but if you are unable to change that setting
or you would like to override it then you can specify the CA bundle file location here.

## Project-level configuration options

### Allow normal users (non-administrators) to configure API connections

This defaults to use the system setting. If set to *allow* or *deny*, it will override the
system-wide setting.

This setting is only available to administrators.

## API Client connections

To create a new API client connection, or view/edit existing connections, use the *API Client* link
in the project menu under *External Modules*.

### Connection Configuration

* **Connection Label** &ndash; A descriptive name for the connection.
* **Connection Type** &ndash; This can be either *HTTP/REST* or *SOAP (WSDL)*.
* **Connection is active** &ndash; The connection will only be triggered if this is set to yes.
* **Trigger connection** &ndash; Choose between triggering the connection when a record is saved,
  or according to a schedule.
* **Limit to event/form** &ndash; If triggered on record save, optionally only trigger when the
  specified event and/or form is being saved. It is possible to specify only an event (to trigger
  on all forms for that event), or specify only a form (to trigger on that form on all events).
* **Schedule** &ndash; If triggered on schedule, specify when to trigger the connection. Note that
  the schedule is not checked every minute, so the time should be treated as approximate. The
  schedule will be run according to your server's time zone. The maximum frequency of a scheduled
  connection is once per day.<br>
  The schedule setting follows UNIX cron format. Enter values as follows:
  * Minute of the hour (0-59)
  * Hour of the day (0-23)
  * Day of the month (1-31)
  * Month (1-12)
  * Day of the week (0-6, 0 = Sunday, 6 = Saturday)
  - For any of the parameters, you can enter `*` to match all, or use ranges such as `3-5`, slashes
    to specify step (e.g. `5/10` for every 10th starting from 5), or separate several values with
    commas.
* **Check conditional logic** &ndash; Optionally enter REDCap conditional logic here. If conditional
  logic is entered, the connection will only be triggered on records which satisfy the condition.

### HTTP Endpoint / Request

These fields only apply to HTTP/REST connections.

* **URL** &ndash; The URL of the API server.
  * To make an API request to the same REDCap server, enter the placeholder
    `REDCAP_APP_PATH_API_FULL` here.
  * Use `null:` as the URL to return a blank response instead of performing an API request. This can
    be useful to simply set the response fields based on some conditional logic.
* **HTTP Method** &ndash; The HTTP method to use. The documentation for the API you are connecting
  to should tell you the appropriate HTTP method.
* **Request Headers** &ndash; Any HTTP headers that the API server expects in the request.
  * To simulate a HTML form submission, use `Content-Type: application/x-www-form-urlencoded`
    (this is automatically added when selecting *form field mode*) or
    `Content-Type: multipart/form-data`, as appropriate for the request body format.
* **Request Body** (POST and PUT requests only) &ndash; The data to submit in the request. This
  module will submit data exactly as provided (subject to placeholder replacement), you need to
  check that the data is in the format that the API server is expecting.
  * For POST requests, you can enable *form field mode*, which will instead treat the request body
    as a list of fields and values, with the field name and value separated by an equals sign (`=`).
    * If this mode is used then raw data values can be entered directly, the request body will be
      automatically converted to a URL-encoded string suitable for a form submission.
    * Placeholder replacement takes place on each field name and value separately.
    * If more than one `=` is entered on a line, the first `=` is treated as the separator and any
      subsequent `=` characters are treated as literal `=` characters in the value.

### Placeholders

These fields only apply to HTTP/REST connections.

You can create as many placeholders as required.

* **Placeholder Name** &ndash; A string value to search for in the *URL*, *Request Headers* and
  *Request Body*, to replace with the value retrieved from the project record.<br>
  Placeholder names are searched *as is* and are not expected to be enclosed in any delimiter.
  Ensure that placeholder names do not occur elsewhere in the request where they should not be
  substituted.<br>
  If the *also replace defined placeholder names in response value paths* option is selected, then
  for any response field of type *response value*, the name of the response value (JSON path or
  XPath) will also have the placeholder names replaced with the value retrieved from the project
  record.
* **Placeholder Value** &ndash; The field to replace the placeholder name with. Specify the event,
  field and instance. If an instance number is not supplied, the current instance will be used if
  applicable, otherwise the latest instance will be used.
  * You can also specify how the field is to be interpreted, see the *Field Interpretation* section
  of this document.
* **Placeholder Format** &ndash; Specify how the value is to be encoded in the HTTP request.
  The documentation for the API you are connecting to should tell you if a particular encoding is
  expected.
  * *Raw value* will insert the data into the request as is. This could cause problems if the data
    contains special characters, so you may need to consider an encoded format (unless *form field
    mode* is used).
  * *Base 64* converts the data into base 64 format, so e.g. `Base 64 string` becomes
    `QmFzZSA2NCBzdHJpbmc=`.
  * *JSON string* will encode the data into a string formatted for use in JSON data, so e.g.
    `JSON éncoded "string"` becomes `"JSON \u00e9ncoded \"string\""`.
  * *URL encode* will encode the data in a format suitable for use in URLs, so e.g.
    `URL éncoded/string` becomes `URL%20%C3%A9ncoded%2Fstring`.
  * *XML encode* will encode the data in a format suitable for use in XML, so e.g.
    `XML 'éncoded" <string>` becomes `XML &apos;&#233;ncoded&quot; &lt;string&gt;`.

### SOAP (WSDL) Endpoint

These fields only apply to SOAP (WSDL) connections.

* **WSDL URL** &ndash; The URL of the API server.
* **Function Name** &ndash; The function to call on the server.

### SOAP (WSDL) Parameters

These fields only apply to SOAP (WSDL) connections.

Specify the function parameters to pass to the API server. You can specify as many function
parameters as required.

* **Parameter Name** &ndash; The name of the function parameter.
* **Parameter Type** &ndash; The type of parameter.
  * *Constant value* will supply a specific value as the parameter value.
  * *Project field* will look up a field value from the project record for the parameter value.
* **Parameter Value** &ndash; If *constant value* is used, enter the value here.
* **Parameter Field** &ndash; If *project field* is used, specify the event, field and instance. If
  an instance number is not supplied, the current instance will be used if applicable, otherwise the
  latest instance will be used.
  * You can also specify how the field is to be interpreted, see the *Field Interpretation* section
  of this document.

### Response Fields

*For HTTP/REST connections, you will need to specify the* status codes to accept *(as a comma
separated list of 3 digit status codes, default=200) and the* response format, *which can be one of:*
* *None/Ignore* &ndash; The request is sent, but the response (if any) is ignored and response
  fields set to use a response value will have no effect.
* *CSV* &ndash; Comma Separated Values (delimiter = `,` and enclosure = `"`).
* *JSON*
* *Plain text* &ndash; Suitable for any text response without special formatting.
* *XML*

Specify the fields of the project record into which response values are to be stored. You can
specify as many response fields as required.

* **Response Field** &ndash; Specify the event, field and instance. If an instance number is not
  supplied, the current instance will be used if applicable, otherwise the latest instance will be
  used.
* **Response Type** &ndash; The type of response.
  * *Constant value* will store a specific value into the field.
  * *Return/response value* will store a named return/response value from the API request into the
    field.
  * *Server date/time* will store the date and time the connection took place, in the server's time
    zone.
  * *UTC date/time* will store the date and time the connection took place, in the UTC time zone.
* **Response Value** &ndash; If *constant value* is used, enter the value here. If *return/response
  value* is used, enter the name of the return/response value here.

If you are using a return/response value, the format of the value name will depend on the connection
type and the response format (if applicable).

* HTTP/REST: Use the [XPath](https://en.wikipedia.org/wiki/XPath) to the value. For non-XML
  responses, the response is converted into XML so it can be searched with XPath - details below.
  JSON responses also support JSON path, as used by the
  [MySQL JSON_EXTRACT() function](https://dev.mysql.com/doc/refman/5.7/en/json-search-functions.html#function_json-extract).
* SOAP (WSDL): Use the name of the return value provided in the SOAP response.

#### Conversion to XML

HTTP/REST responses which are not XML are converted to an XML format so that values can be easily
extracted using an XPath expression. Examples of the conversion for each data format are below:

**CSV** - Original data:
```csv
"first field", "2nd field", "field 3"
"some data", 27, true
```
XML representation:
```xml
<root>
 <line>
  <item>first field</item>
  <item>2nd field</item>
  <item>field 3</item>
 </line>
 <line>
  <item header="first field">some data</item>
  <item header="2nd field">27</item>
  <item header="field 3">true</item>
 </line>
</root>
```

**JSON** - Original data:
```json
{
  "array_field" : [ "a", "b", "c" ],
  "object_field" : { "a" : 1, "b" : "z" },
  "bool_true" : true,
  "bool_false" : false,
  "no_data" : null
}
```
XML representation:
```xml
<root>
 <array_field>
  <item>a</item>
  <item>b</item>
  <item>c</item>
 </array_field>
 <object_field>
  <a>1</a>
  <b>z</b>
 </object_field>
 <bool_true>1</bool_true>
 <bool_false>0</bool_false>
 <no_data></no_data>
</root>
```

**Plain text** - Original data:
```
This is the first line.
This is the second line.
This is the third line.
```
XML representation:
```xml
<root>
 <line>This is the first line.</line>
 <line>This is the second line.</line>
 <line>This is the third line.</line>
</root>
```


## Field Interpretation

When extracting data from a field, there are sometimes different ways that the data can be
interpreted. The field interpretation option allows you to apply a transformation to the data.

For some transformations, it is necessary to supply transformation parameters in the text box which
follows the field interpretation selection.

* **Normal text** &ndash; This is the default option. No transformation is applied.
* **Format date** &ndash; This option will assume the input is a date or datetime, and format it
  according to the transformation parameters, which must be in
  [PHP date format](https://www.php.net/manual/en/datetime.format.php).
* **Get line** &ndash; For multi-line (notes) fields, get only the line specified in the
  transformation parameters, where 0 is the first line, 1 is the second line etc. Negative numbers
  can be used to count from the end, use -1 for the last line, -2 for the penultimate line etc.
* **Concatenate lines** &ndash; For multi-line (notes) fields, convert into a single line using the
  value of the transformation parameters as the separator.
* **File MIME type** &ndash; For file upload fields, returns the MIME type of the file instead of
  the file data.


## API Connection Debugger

Load the API connection debugger when testing an API connection, to check the values retrieved from
the REDCap project, the placeholder replacement, the request/response, and the new saved values to
the REDCap project. Keep the debugger page open in a separate tab/window while triggering a
connection and the results will be shown on the debugger screen.
