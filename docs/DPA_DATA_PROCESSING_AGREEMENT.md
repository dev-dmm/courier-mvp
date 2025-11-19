# Data Processing Agreement (DPA)

## Between Eshop and oreksi.gr

**This is a template Data Processing Agreement. Customize with actual names, addresses, and contact information.**

---

## PARTIES

**Data Controller:**
- **Name:** [ESHOP NAME]
- **Address:** [ESHOP ADDRESS]
- **Contact:** [CONTACT EMAIL]
- **Representative:** [NAME, TITLE]

**Data Processor:**
- **Name:** oreksi.gr / Courier Intelligence Platform
- **Address:** [OREKSI ADDRESS]
- **Contact:** [privacy@oreksi.gr]
- **Representative:** [NAME, TITLE]

**Date:** [DATE]

---

## 1. DEFINITIONS

1.1 **"GDPR"** means Regulation (EU) 2016/679 (General Data Protection Regulation).

1.2 **"Data Controller"** means the Eshop that determines the purposes and means of processing personal data.

1.3 **"Data Processor"** means oreksi.gr, which processes personal data on behalf of the Data Controller.

1.4 **"Personal Data"** means any information relating to an identified or identifiable natural person.

1.5 **"Processing"** means any operation performed on personal data (collection, storage, analysis, etc.).

1.6 **"Pseudonymized Data"** means personal data that has been processed so that it can no longer be attributed to a specific data subject without the use of additional information (hashing).

1.7 **"Data Subject"** means the natural person (customer) whose personal data is processed.

---

## 2. SUBJECT MATTER AND DURATION

2.1 **Purpose of Processing:**
   - Delivery risk assessment
   - Fraud prevention
   - Delivery optimization analytics
   - Cross-shop customer behavior analysis

2.2 **Duration:**
   This Agreement shall remain in effect for as long as the Data Controller uses the services of the Data Processor, and for the retention period specified in Section 7.

2.3 **Scope:**
   This Agreement covers all processing of personal data by the Data Processor on behalf of the Data Controller in connection with the Courier Intelligence service.

---

## 3. NATURE AND PURPOSE OF PROCESSING

3.1 **Data Categories Processed:**
   - Pseudonymized customer identifiers (hashed email, phone, name, address)
   - Order data (amount, status, payment method, shipping method)
   - Delivery outcomes (successful, failed, returned)
   - Shipping location data (city, country)

3.2 **Processing Activities:**
   - Receiving pseudonymized order data from Data Controller
   - Storing pseudonymized data in secure database
   - Calculating delivery risk scores
   - Providing risk scores to Data Controller
   - Analyzing delivery patterns across multiple stores
   - Generating aggregated statistics

3.3 **Data Subjects:**
   - Customers of the Data Controller who place orders

3.4 **Special Categories:**
   - **NO** special categories of personal data are processed (no health, biometric, or sensitive data)

---

## 4. OBLIGATIONS OF DATA PROCESSOR

4.1 **Compliance:**
   The Data Processor shall:
   - Process personal data only in accordance with this Agreement and documented instructions from the Data Controller
   - Comply with all applicable data protection laws, including GDPR
   - Not process personal data for any purpose other than those specified in this Agreement

4.2 **Security Measures:**
   The Data Processor shall implement appropriate technical and organizational measures to ensure a level of security appropriate to the risk, including:
   - **Encryption in transit:** All data transmitted via HTTPS
   - **Authentication:** HMAC signatures for API access
   - **Pseudonymization:** All PII hashed using SHA256 with salt
   - **Access controls:** Role-based access, audit logs
   - **Data minimization:** Only necessary data collected and stored
   - **Regular security assessments:** Vulnerability scanning, penetration testing

4.3 **Confidentiality:**
   The Data Processor shall ensure that persons authorized to process personal data are bound by confidentiality obligations.

4.4 **Assistance:**
   The Data Processor shall assist the Data Controller in:
   - Responding to data subject rights requests
   - Conducting Data Protection Impact Assessments (DPIAs)
   - Notifying supervisory authorities of data breaches
   - Providing information necessary to demonstrate compliance

4.5 **Sub-processors:**
   The Data Processor may engage sub-processors (e.g., hosting providers) only with:
   - Prior written consent of the Data Controller
   - Sub-processor bound by same obligations as this Agreement
   - Data Controller retains right to object to sub-processors

4.6 **Data Breach Notification:**
   The Data Processor shall notify the Data Controller **without undue delay** (within 72 hours) of any personal data breach, providing:
   - Description of the breach
   - Categories and approximate number of data subjects affected
   - Likely consequences
   - Measures taken or proposed to address the breach

4.7 **Records:**
   The Data Processor shall maintain records of all processing activities carried out on behalf of the Data Controller.

---

## 5. OBLIGATIONS OF DATA CONTROLLER

5.1 **Lawful Basis:**
   The Data Controller warrants that it has a lawful basis for processing personal data (Legitimate Interest under GDPR Article 6(1)(f)).

5.2 **Privacy Policy:**
   The Data Controller shall:
   - Inform data subjects about the processing in its privacy policy
   - Include information about oreksi.gr as a data processor
   - Explain the purpose of processing (delivery risk assessment)

5.3 **Data Subject Rights:**
   The Data Controller shall:
   - Handle data subject rights requests (access, rectification, erasure, etc.)
   - Inform data subjects of their rights
   - Respond to requests within 30 days

5.4 **Instructions:**
   The Data Controller shall provide clear, documented instructions for processing.

5.5 **Compliance:**
   The Data Controller shall comply with all applicable data protection laws.

---

## 6. DATA SUBJECT RIGHTS

6.1 **Right to Access:**
   Upon request from Data Controller, Data Processor shall provide information about personal data processed.

6.2 **Right to Rectification:**
   Data Processor shall correct inaccurate data upon instruction from Data Controller.

6.3 **Right to Erasure (Right to be Forgotten):**
   Data Processor shall delete personal data upon instruction from Data Controller, within 30 days.

6.4 **Right to Object:**
   Data Processor shall stop processing upon instruction from Data Controller if data subject objects.

6.5 **Right to Portability:**
   Data Processor shall provide data in machine-readable format (JSON) upon request.

6.6 **Implementation:**
   Data Processor shall implement technical measures to facilitate data subject rights.

---

## 7. DATA RETENTION AND DELETION

7.1 **Retention Period:**
   Personal data shall be retained for **24 months** from the last order, unless:
   - Data subject requests earlier deletion
   - Data Controller instructs earlier deletion
   - Legal obligation requires longer retention

7.2 **Deletion:**
   Upon expiry of retention period or upon request:
   - All personal data (hashed identifiers and associated data) shall be permanently deleted
   - Deletion confirmed in writing to Data Controller
   - Backups containing personal data shall be deleted within 90 days

7.3 **Aggregated Data:**
   Aggregated statistics (without personal identification) may be retained beyond retention period.

---

## 8. SECURITY MEASURES

8.1 **Technical Measures:**
   - Encryption in transit (HTTPS/TLS)
   - Pseudonymization (SHA256 hashing with salt)
   - Access controls (role-based, multi-factor authentication)
   - Audit logs (all access logged and monitored)
   - Regular security updates and patches
   - Intrusion detection and prevention

8.2 **Organizational Measures:**
   - Staff training on data protection
   - Confidentiality agreements for all staff
   - Incident response procedures
   - Regular security assessments
   - Data Protection Officer (DPO) appointed

8.3 **Physical Security:**
   - Secure data centers
   - Access controls to facilities
   - Backup and disaster recovery procedures

---

## 9. DATA BREACH

9.1 **Notification:**
   Data Processor shall notify Data Controller **without undue delay** (within 72 hours) of any personal data breach.

9.2 **Information Provided:**
   Notification shall include:
   - Description of the breach
   - Categories and approximate number of data subjects affected
   - Likely consequences
   - Measures taken or proposed

9.3 **Cooperation:**
   Data Processor shall cooperate with Data Controller in investigating and remediating breaches.

9.4 **Regulatory Notification:**
   Data Controller shall be responsible for notifying supervisory authorities and data subjects (if required).

---

## 10. AUDITS AND COMPLIANCE

10.1 **Audit Rights:**
    Data Controller has the right to:
    - Audit Data Processor's compliance with this Agreement
    - Request documentation of security measures
    - Conduct on-site audits (with reasonable notice)

10.2 **Certifications:**
    Data Processor shall maintain relevant security certifications (ISO 27001, SOC 2, etc.) if applicable.

10.3 **Compliance Reports:**
    Data Processor shall provide annual compliance reports upon request.

---

## 11. LIABILITY AND INDEMNIFICATION

11.1 **Liability:**
    Each party shall be liable for damages caused by its breach of this Agreement or GDPR.

11.2 **Limitation:**
    Liability is limited to direct damages, excluding indirect, consequential, or punitive damages (to the extent permitted by law).

11.3 **Indemnification:**
    Data Processor shall indemnify Data Controller for damages resulting from Data Processor's breach of this Agreement or GDPR.

---

## 12. TERMINATION

12.1 **Termination:**
    Either party may terminate this Agreement with 30 days written notice.

12.2 **Return/Deletion of Data:**
    Upon termination:
    - Data Processor shall return or delete all personal data
    - Deletion confirmed in writing
    - No copies retained (except as required by law)

12.3 **Survival:**
    Sections on confidentiality, liability, and data deletion shall survive termination.

---

## 13. GENERAL PROVISIONS

13.1 **Governing Law:**
    This Agreement shall be governed by [COUNTRY] law.

13.2 **Dispute Resolution:**
    Disputes shall be resolved through [ARBITRATION/COURTS] in [LOCATION].

13.3 **Amendments:**
    This Agreement may only be amended in writing and signed by both parties.

13.4 **Severability:**
    If any provision is invalid, the remainder of the Agreement shall remain in effect.

13.5 **Entire Agreement:**
    This Agreement constitutes the entire agreement between the parties regarding data processing.

---

## SIGNATURES

**Data Controller:**

Name: _______________________  
Title: _______________________  
Signature: _______________________  
Date: _______________________

**Data Processor (oreksi.gr):**

Name: _______________________  
Title: _______________________  
Signature: _______________________  
Date: _______________________

---

## APPENDIX A: SUB-PROCESSORS

**Current Sub-processors:**

1. **Hosting Provider:**
   - Name: [HOSTING COMPANY]
   - Service: Cloud hosting, database
   - Location: [COUNTRY]
   - DPA: [YES/NO]

2. **Backup Provider:**
   - Name: [BACKUP COMPANY]
   - Service: Data backup
   - Location: [COUNTRY]
   - DPA: [YES/NO]

**Note:** Data Controller will be notified of any new sub-processors and may object within 30 days.

---

**Document Version:** 1.0  
**Last Updated:** [DATE]  
**Effective Date:** [DATE]

