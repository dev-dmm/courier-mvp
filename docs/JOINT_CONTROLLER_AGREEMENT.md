# Joint Controller Agreement

## Between Eshop and oreksi.gr

**Use this agreement if oreksi.gr and the eshop jointly determine the purposes and means of processing (Joint Controllers under GDPR Article 26).**

---

## PARTIES

**Joint Controller 1:**
- **Name:** [ESHOP NAME]
- **Address:** [ESHOP ADDRESS]
- **Contact:** [CONTACT EMAIL]
- **Representative:** [NAME, TITLE]

**Joint Controller 2:**
- **Name:** oreksi.gr / Courier Intelligence Platform
- **Address:** [OREKSI ADDRESS]
- **Contact:** [privacy@oreksi.gr]
- **Representative:** [NAME, TITLE]

**Date:** [DATE]

---

## 1. DEFINITIONS

1.1 **"GDPR"** means Regulation (EU) 2016/679 (General Data Protection Regulation).

1.2 **"Joint Controllers"** means the parties who jointly determine the purposes and means of processing personal data (GDPR Article 26).

1.3 **"Personal Data"** means any information relating to an identified or identifiable natural person.

1.4 **"Processing"** means any operation performed on personal data.

1.5 **"Data Subject"** means the natural person (customer) whose personal data is processed.

---

## 2. JOINT CONTROLLERSHIP

2.1 **Recognition:**
   The parties acknowledge that they are **Joint Controllers** under GDPR Article 26, as they jointly determine:
   - The **purposes** of processing (delivery risk assessment, fraud prevention)
   - The **means** of processing (data collection, hashing, storage, analysis)

2.2 **Shared Responsibility:**
   Both parties are responsible for compliance with GDPR, subject to the allocation of responsibilities in this Agreement.

---

## 3. PURPOSES AND MEANS OF PROCESSING

3.1 **Purposes:**
   - Delivery risk assessment
   - Fraud prevention
   - Delivery optimization analytics
   - Cross-shop customer behavior analysis

3.2 **Means:**
   - Collection of order data from eshops
   - Pseudonymization (hashing) of PII
   - Storage of pseudonymized data
   - Calculation of risk scores
   - Provision of scores to eshops

3.3 **Data Categories:**
   - Pseudonymized customer identifiers (hashed email, phone, name, address)
   - Order data (amount, status, payment method, shipping method)
   - Delivery outcomes (successful, failed, returned)
   - Shipping location data (city, country)

---

## 4. ALLOCATION OF RESPONSIBILITIES

### 4.1 Eshop Responsibilities

**Eshop (Joint Controller 1) is responsible for:**

✅ **Data Collection:**
   - Collecting order data from customers
   - Obtaining necessary consents/informing customers (privacy policy)
   - Ensuring lawful basis for processing (Legitimate Interest)

✅ **Data Transmission:**
   - Hashing PII before transmission
   - Secure transmission via HTTPS
   - HMAC authentication

✅ **Customer Communication:**
   - Informing customers about processing in privacy policy
   - Handling data subject rights requests (access, rectification, erasure)
   - Responding to customer inquiries

✅ **Compliance:**
   - Maintaining lawful basis documentation
   - Conducting Legitimate Interest Assessment (LIA)
   - Ensuring privacy policy is up to date

### 4.2 oreksi.gr Responsibilities

**oreksi.gr (Joint Controller 2) is responsible for:**

✅ **Data Storage:**
   - Secure storage of pseudonymized data
   - Implementing technical and organizational security measures
   - Access controls and audit logs

✅ **Data Processing:**
   - Calculating risk scores
   - Analyzing delivery patterns
   - Generating aggregated statistics

✅ **Technical Infrastructure:**
   - Maintaining secure API endpoints
   - Ensuring encryption in transit
   - Regular security assessments

✅ **Compliance:**
   - Maintaining processing records
   - Assisting with data subject rights
   - Data breach notification procedures

### 4.3 Shared Responsibilities

**Both parties are jointly responsible for:**

✅ **Data Minimization:**
   - Ensuring only necessary data is processed
   - Implementing retention policies (24 months)

✅ **Security:**
   - Implementing appropriate technical and organizational measures
   - Regular security assessments

✅ **Transparency:**
   - Ensuring customers are informed about processing
   - Providing clear privacy notices

---

## 5. DATA SUBJECT RIGHTS

5.1 **Single Point of Contact:**
   **Eshop** shall be the **primary point of contact** for data subject rights requests.

5.2 **Cooperation:**
   - Eshop receives and handles requests
   - oreksi.gr assists Eshop in responding (providing data, deleting data, etc.)
   - Response provided within 30 days

5.3 **Rights Covered:**
   - Right to Access
   - Right to Rectification
   - Right to Erasure (Right to be Forgotten)
   - Right to Object
   - Right to Portability
   - Right to Restrict Processing

5.4 **Implementation:**
   - Eshop implements customer-facing mechanisms
   - oreksi.gr provides technical support (API endpoints, deletion tools)

---

## 6. TRANSPARENCY AND INFORMATION

6.1 **Privacy Policy:**
   **Eshop** shall include in its privacy policy:
   - Information about oreksi.gr as a joint controller
   - Purpose of processing (delivery risk assessment)
   - Contact information for both joint controllers
   - Data subject rights and how to exercise them

6.2 **Contact Information:**
   Both parties shall provide:
   - Contact email for privacy inquiries
   - Contact address
   - Data Protection Officer (DPO) contact (if applicable)

6.3 **Joint Notice:**
   Customers shall be informed that:
   - Their data is processed by both eshop and oreksi.gr
   - Both parties are joint controllers
   - They can contact either party for privacy inquiries

---

## 7. SECURITY MEASURES

7.1 **Technical Measures:**
   Both parties shall implement:
   - Encryption in transit (HTTPS/TLS)
   - Pseudonymization (SHA256 hashing with salt)
   - Access controls (role-based, multi-factor authentication)
   - Audit logs
   - Regular security updates

7.2 **Organizational Measures:**
   - Staff training on data protection
   - Confidentiality agreements
   - Incident response procedures
   - Regular security assessments

7.3 **Cooperation:**
   Both parties shall:
   - Share security best practices
   - Notify each other of security incidents
   - Cooperate in security investigations

---

## 8. DATA BREACH

8.1 **Notification:**
   In case of a personal data breach:
   - **oreksi.gr** shall notify **Eshop** without undue delay (within 72 hours)
   - **Eshop** shall notify supervisory authorities and data subjects (if required)
   - Both parties shall cooperate in investigation and remediation

8.2 **Information Provided:**
   Notification shall include:
   - Description of the breach
   - Categories and approximate number of data subjects affected
   - Likely consequences
   - Measures taken or proposed

---

## 9. DATA RETENTION AND DELETION

9.1 **Retention Period:**
   Personal data shall be retained for **24 months** from the last order, unless:
   - Data subject requests earlier deletion
   - Legal obligation requires longer retention

9.2 **Deletion:**
   Upon expiry of retention period or upon request:
   - **oreksi.gr** shall delete all personal data
   - **Eshop** shall confirm deletion
   - Both parties shall ensure no copies retained (except as required by law)

9.3 **Cooperation:**
   Both parties shall cooperate to ensure timely and complete deletion.

---

## 10. LIABILITY

10.1 **Joint Liability:**
    Under GDPR Article 26(3), data subjects may exercise their rights against **either** joint controller.

10.2 **Internal Allocation:**
    - **Eshop** is primarily liable for:
      - Customer communication
      - Privacy policy compliance
      - Data collection and transmission
    
    - **oreksi.gr** is primarily liable for:
      - Data storage security
      - Technical infrastructure
      - Data processing operations

10.3 **Indemnification:**
    Each party shall indemnify the other for damages caused by its breach of this Agreement or GDPR, subject to the allocation of responsibilities above.

10.4 **Limitation:**
    Liability is limited to direct damages, excluding indirect, consequential, or punitive damages (to the extent permitted by law).

---

## 11. TERMINATION

11.1 **Termination:**
    Either party may terminate this Agreement with 30 days written notice.

11.2 **Return/Deletion of Data:**
    Upon termination:
    - **oreksi.gr** shall return or delete all personal data
    - Deletion confirmed in writing
    - No copies retained (except as required by law)

11.3 **Survival:**
    Sections on liability, data deletion, and confidentiality shall survive termination.

---

## 12. GENERAL PROVISIONS

12.1 **Governing Law:**
    This Agreement shall be governed by [COUNTRY] law.

12.2 **Dispute Resolution:**
    Disputes shall be resolved through [ARBITRATION/COURTS] in [LOCATION].

12.3 **Amendments:**
    This Agreement may only be amended in writing and signed by both parties.

12.4 **Severability:**
    If any provision is invalid, the remainder of the Agreement shall remain in effect.

12.5 **Entire Agreement:**
    This Agreement constitutes the entire agreement between the parties regarding joint controllership.

---

## SIGNATURES

**Joint Controller 1 (Eshop):**

Name: _______________________  
Title: _______________________  
Signature: _______________________  
Date: _______________________

**Joint Controller 2 (oreksi.gr):**

Name: _______________________  
Title: _______________________  
Signature: _______________________  
Date: _______________________

---

## APPENDIX A: CONTACT INFORMATION

**For Data Subject Rights Requests:**

**Eshop:**
- Email: [privacy@eshop.com]
- Address: [ESHOP ADDRESS]
- Phone: [PHONE]

**oreksi.gr:**
- Email: [privacy@oreksi.gr]
- Address: [OREKSI ADDRESS]
- Phone: [PHONE]

**Note:** Data subjects may contact either party to exercise their rights.

---

**Document Version:** 1.0  
**Last Updated:** [DATE]  
**Effective Date:** [DATE]

