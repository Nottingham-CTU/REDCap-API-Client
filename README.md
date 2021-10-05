# REDCap-API-Client

This REDCap module allows data to be sent to and received from an external API.

## System-level configuration options

### Allow normal users (non-administrators) to configure API connections

If enabled, anyone with design/setup rights in a project can configure API connections for that
project. If disabled, only administrators can configure API connections. This setting can be
overridden for individual projects.

### File path of cURL CA bundle

If HTTP/REST connections to secure (https) endpoints fail, this may be because the cURL library does
not have a set of trusted certificate authorities. To fix this, obtain a file of trusted certificate
authority root certificates, place the file somewhere on the server, and provide the file path to
that file here.

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
  * Day of the month (1-31, or enter \* to match all days)
  * Month (1-12, or enter \* to match all months)
  * Day of the week (0-6, 0 = Sunday, 6 = Saturday, or enter \* to match all days)
* **Check conditional logic** &ndash; Optionally enter REDCap conditional logic here. If conditional
  logic is entered, the connection will only be triggered on records which satisfy the condition.

### HTTP Endpoint / Request

These fields only apply to HTTP/REST connections.

* **URL** &ndash; The URL of the API server.
* **HTTP Method** &ndash; The HTTP method to use. The documentation for the API you are connecting
  to should tell you the appropriate HTTP method.
* **Request Headers** &ndash; Any HTTP headers that the API server expects in the request.
* **Request Body** (POST and PUT requests only) &ndash; The data to submit in the request. This
  module will submit data exactly as provided (subject to placeholder replacement), you need to
  check that the data is in the format that the API server is expecting.

### Placeholders

These fields only apply to HTTP/REST connections.

You can create as many placeholders as required.

* **Placeholder Name** &ndash; A string value to search for in the *URL*, *Request Headers* and
  *Request Body*, to replace with the value retrieved from the project record.
* **Placeholder Value** &ndash; The field to replace the placeholder name with. Specify the event,
  field and instance. If an instance number is not supplied, the current instance will be used if
  applicable, otherwise the latest instance will be used.
  * You can also specify how the field is to be interpreted, see the *Field Interpretation* section
  of this document.
* **Placeholder Format** &ndash; Specify how the value is to be encoded in the HTTP request.
  * *Raw value* will insert the data into the request as is. This could cause problems if the data
    contains special characters, so you may need to consider an encoded format.
  * *Base 64* and *URL encode* will apply that form of encoding. The documentation for the API you
    are connecting to should tell you if a particular encoding is expected.

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

*For HTTP/REST connections, you will need to specify the* response format, *which can be one of:*
* *None/Ignore* &ndash; The request is sent, but the response (if any) is ignored and response
  fields are not used.
* *JSON* &ndash; The response is treated as JSON.
* *XML* &ndash; The response is treated as XML.

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

* HTTP/REST - JSON: Use the JSON path to the value, as used by the
  [MySQL JSON_EXTRACT() function](https://dev.mysql.com/doc/refman/5.7/en/json-search-functions.html#function_json-extract).
* HTTP/REST - XML: Use the [XPath](https://en.wikipedia.org/wiki/XPath) to the value.
* SOAP (WSDL): Use the name of the return value provided in the SOAP response.


## Field Interpretation

When extracting data from a field, there are sometimes different ways that the data can be
interpreted. The field interpretation option allows you to apply a transformation to the data.

For some transformations, it is necessary to supply transformation parameters in the text box which
follows the field interpretation selection.

* **Normal text** &ndash; This is the default option. No transformation is applied.
* **Format date** &ndash; This option will assume the input is a date or datetime, and format it
  according to the transformation parameters, which must be in
  [PHP date format](https://www.php.net/manual/en/datetime.format.php).
