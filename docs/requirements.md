We want to build a simple crm.
- Customers - create users with the sql credentials below
- Create document which can either be a delivery note or an invoice based on the type field.
- Each document will have line items with details, quantity, price, per and line value.
- The difference between delivery notes and invoices is that delivery notes are used to record the delivery of goods or services, while invoices are used to request payment for those goods or services. In our system, we want to be able to create both types of documents and link them to customers.
- Delivery notes can be converted to invoices, but not the other way around.
- Delivery notes and invoices can be emailed to customers, and we want to log the email history for each document.
- We want to be able to set trade discounts, credit terms and credit limits for each customer, and these should be stored in separate lookup tables.
- The system should be able to calculate the subtotal, VAT amount and total value for each document based on the line items and any applicable discounts or credit terms.
- The system should also be able to generate unique document numbers and order numbers for each document, and these should be stored in the documents table.
- The system should have a status field for each document to indicate whether it is active, converted (for delivery notes that have been converted to invoices) or emailed.

UI requirements:
- We just want a simple UI and easily manageable UI, no need for a fancy design. The UI should be intuitive and user-friendly, allowing users to easily navigate and perform the necessary actions.
- We want to allow the use of shortcut and hotkeys to speed up the workflow, such as creating a new customer, creating a new document, converting a delivery note to an invoice, and emailing a document.
- The UI should allow users to create and manage lookup data for titles, credit terms and credit limits, which can be used when creating or editing customers.
- The UI should allow users to create and manage customers, including setting their trade discounts, credit terms and credit limits.
- The UI should allow users to create and manage documents (delivery notes and invoices), including adding line items, calculating totals, and changing the status of the document.
- Invoices cannot be created directly from the UI from scratch, they must be created by converting a delivery note. The UI should provide an option to convert a delivery note to an invoice, and this should automatically populate the invoice with the details from the delivery note.
- After converting a delivery note to an invoice, there will be a form to update the invoice items such as quantity, price, per and line value. The form should also allow users to add or remove line items from the invoice.
- The UI should also allow users to email documents to customers, and this should trigger the logging of the email history for that document.
- The UI should provide a way to view the email history for each document, including the recipient email, sent date and time, and status of the email (sent or failed).
- The UI should also provide a way to view the details of each document, including the line items, totals, and customer information.
- No need to call it a document, we just call it delivery note or invoice in the UI. The document table is just a backend implementation detail to store both types of documents in a single table.
- The UI should also provide a way to filter and search for customers and documents based on various criteria such as company name, document type, date range, etc.
- Create a nice invoice template for export to pdf and printing, which includes the company logo, customer details, line items, totals, and any applicable discounts or credit terms. The template should be professional and easy to read.

These are the requirements for our simple CRM system. Incase there are things I missed, please let me know and I can clarify further. This system will be built in phases.
I may have missed some things, do well to provide suggestions of what not to have and what to have.
This table is just a p-o-c, do well to modify as seems fit to meet the requirements and make it more efficient.

Also write tests to verify the functionality and flow of the system/componnents from top to bottom.
---------------------------------------------------------------------------------
-- 1. Child Lookup Tables 

CREATE TABLE lookup_titles (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(20)); 

CREATE TABLE lookup_credit_terms (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50)); 

CREATE TABLE lookup_credit_limits (id INT AUTO_INCREMENT PRIMARY KEY, amount DECIMAL(15,2)); 

 

-- 2. Main Customer Table 

CREATE TABLE customers ( 

    id INT AUTO_INCREMENT PRIMARY KEY, 

    company_name VARCHAR(255) NOT NULL, 

    reference VARCHAR(50) UNIQUE, 

    title_id INT, 

    first_name VARCHAR(100), 

    last_name VARCHAR(100), 

    address_1 VARCHAR(255), 

    address_2 VARCHAR(255), 

    town VARCHAR(100), 

    post_code VARCHAR(20), 

    email_1 VARCHAR(255), 

    trade_discount DECIMAL(5,2) DEFAULT 0.00, 

    credit_term_id INT, 

    credit_limit_id INT, 

    FOREIGN KEY (title_id) REFERENCES lookup_titles(id), 

    FOREIGN KEY (credit_term_id) REFERENCES lookup_credit_terms(id), 

    FOREIGN KEY (credit_limit_id) REFERENCES lookup_credit_limits(id) 

); 

 

-- 3. Document Header (DN & Invoices) 

CREATE TABLE documents ( 

    id INT AUTO_INCREMENT PRIMARY KEY, 

    customer_id INT NOT NULL, 

    type ENUM('DN', 'INV') NOT NULL, 

    doc_number VARCHAR(20) UNIQUE, 

    order_no VARCHAR(50), -- Auto generated 

    doc_date DATE NOT NULL, 

    subtotal DECIMAL(15,2) DEFAULT 0.00, 

    vat_amount DECIMAL(15,2) DEFAULT 0.00, 

    total_value DECIMAL(15,2) DEFAULT 0.00, 

    status ENUM('active', 'converted', 'emailed') DEFAULT 'active', 

    FOREIGN KEY (customer_id) REFERENCES customers(id) 

); 

 

-- 4. Line Items 

CREATE TABLE document_items ( 

    id INT AUTO_INCREMENT PRIMARY KEY, 

    document_id INT NOT NULL, 

    details TEXT NOT NULL, 

    quantity DECIMAL(15,2) NOT NULL, 

    price DECIMAL(15,2) DEFAULT 0.00, 

    per VARCHAR(20), 

    line_value DECIMAL(15,2) DEFAULT 0.00, 

    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE 

); 

 

-- 5. Email Logs 

CREATE TABLE document_email_logs ( 

    id INT AUTO_INCREMENT PRIMARY KEY, 

    document_id INT NOT NULL, 

    recipient_email VARCHAR(255), 

    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 

    status ENUM('sent', 'failed') DEFAULT 'sent', 

    FOREIGN KEY (document_id) REFERENCES documents(id) 

); 

 
