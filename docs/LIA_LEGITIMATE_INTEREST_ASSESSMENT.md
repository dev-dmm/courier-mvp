# Legitimate Interest Assessment (LIA)

## Document Information

- **Organization:** oreksi.gr / Courier Intelligence Platform
- **Assessment Date:** [DATE]
- **Assessed By:** [NAME, TITLE]
- **Review Date:** [DATE + 1 YEAR]
- **Version:** 1.0

---

## 1. Purpose of Processing

### 1.1 What is the purpose?

We process customer order data to provide **delivery risk assessment** and **fraud prevention** services to e-commerce stores.

**Specific purposes:**
- Calculate delivery reliability scores for customers
- Identify high-risk orders that may result in failed deliveries
- Prevent payment fraud and reduce financial losses for merchants
- Analyze delivery patterns across multiple stores
- Optimize logistics and delivery success rates

### 1.2 Why is this necessary?

E-commerce stores face significant challenges:
- **Failed deliveries** result in financial losses (shipping costs, product returns)
- **Payment fraud** (especially COD - Cash on Delivery) causes direct losses
- **No-show customers** waste logistics resources
- **Lack of historical data** prevents stores from making informed decisions

Our service addresses these challenges by:
- Providing cross-shop customer behavior analysis
- Enabling data-driven risk assessment
- Reducing failed delivery rates
- Protecting merchants from fraud

### 1.3 Is this a legitimate interest?

**YES.** This qualifies as legitimate interest because:

✅ **Fraud prevention** is a recognized legitimate interest under GDPR  
✅ **Business protection** (protecting merchants from losses) is legitimate  
✅ **Delivery optimization** benefits both merchants and customers  
✅ **No alternative** exists that provides the same level of protection without cross-shop data pooling

---

## 2. Necessity Assessment

### 2.1 Is processing necessary for this purpose?

**YES.** Processing is necessary because:

- **Cross-shop analysis requires data pooling:** A single store cannot assess customer reliability across multiple merchants
- **Historical data is essential:** Risk scoring requires past behavior patterns
- **Real-time assessment needs:** Stores need immediate risk scores when orders are placed

### 2.2 Could the purpose be achieved in a less intrusive way?

**NO.** We have implemented the least intrusive approach:

✅ **Pseudonymization:** All PII is hashed before storage (SHA256 with salt)  
✅ **Data minimization:** Only necessary data is collected (order details, delivery outcomes)  
✅ **No raw PII stored:** Email, phone, name, address are never stored in raw form  
✅ **Limited scope:** Data used ONLY for delivery risk assessment, not marketing  
✅ **Transparency:** Customers informed via privacy policy  
✅ **Retention limits:** Data deleted after 24 months

**Alternative approaches considered:**
- ❌ Store-level only: Would not provide cross-shop insights
- ❌ Raw PII storage: Would be more intrusive and risky
- ❌ Longer retention: Would violate data minimization principle

### 2.3 Is there a less intrusive alternative?

**NO.** The current pseudonymized approach is the least intrusive method that achieves the purpose.

---

## 3. Balancing Test

### 3.1 What are the legitimate interests?

**Merchant interests:**
- Protect business from fraud and financial losses
- Reduce failed delivery costs
- Make informed decisions about order acceptance
- Optimize logistics operations

**Customer interests (indirect):**
- Faster, more reliable deliveries
- Better service quality
- Reduced fraud in the ecosystem

**Platform interests:**
- Provide valuable service to merchants
- Maintain trust and security in the platform

### 3.2 What is the impact on individuals?

**Minimal impact** because:

✅ **No automated decisions:** Stores make final decisions, not the system  
✅ **No blacklisting:** System provides scores, not blocking  
✅ **Pseudonymized data:** Individuals cannot be identified from stored data  
✅ **No marketing use:** Data never used for advertising or marketing  
✅ **Opt-out available:** Customers can request deletion  
✅ **Transparent:** Customers informed about processing

**Potential concerns addressed:**
- ❌ **Concern:** "Will I be blocked from ordering?"  
  **Answer:** No. System provides risk scores only. Stores decide.

- ❌ **Concern:** "Will my data be sold?"  
  **Answer:** No. Data used only for delivery risk assessment.

- ❌ **Concern:** "Can I be identified?"  
  **Answer:** No. Only hashed identifiers stored, not raw PII.

### 3.3 Do the legitimate interests outweigh the impact?

**YES.** The balancing test favors legitimate interest because:

1. **Strong legitimate interest:**
   - Fraud prevention is critical for business survival
   - Delivery optimization benefits everyone

2. **Minimal impact:**
   - No direct negative consequences for customers
   - Pseudonymization protects privacy
   - Transparent processing

3. **Proportionality:**
   - Only necessary data processed
   - Limited retention period (24 months)
   - No secondary uses

4. **Safeguards in place:**
   - Technical measures (hashing, encryption)
   - Organizational measures (access controls, retention policies)
   - Legal measures (DPAs, privacy policies)

---

## 4. Data Processing Details

### 4.1 What data is processed?

**Pseudonymized identifiers (hashed):**
- Customer email hash (`customer_hash`)
- Phone hash (optional)
- Name hash (optional)
- Address hash (optional)

**Non-PII order data:**
- Order ID
- Order amount
- Payment method
- Shipping method
- Order status
- Delivery outcomes (successful, failed, returned)
- Shipping location (city, country - not full address)

**What is NOT processed:**
- ❌ Raw email addresses
- ❌ Raw phone numbers
- ❌ Raw names
- ❌ Raw addresses
- ❌ Credit card information
- ❌ Product details (beyond order value)

### 4.2 How is data processed?

1. **Collection:** Data sent from WooCommerce stores via HTTPS API
2. **Hashing:** All PII hashed using SHA256 with global salt (done at store level)
3. **Storage:** Only hashed data stored in database
4. **Analysis:** Risk scores calculated based on historical patterns
5. **Retention:** Data retained for 24 months, then deleted

### 4.3 Who has access?

- **oreksi.gr platform:** Technical staff (limited access, logged)
- **Merchant stores:** Can view risk scores for their own orders only
- **Third parties:** None (no data sharing)

---

## 5. Technical and Organizational Measures

### 5.1 Technical Measures

✅ **Encryption in transit:** HTTPS for all API communications  
✅ **Authentication:** HMAC signatures for API requests  
✅ **Pseudonymization:** SHA256 hashing with salt for all PII  
✅ **Access controls:** Role-based access, audit logs  
✅ **Data minimization:** Only necessary fields stored  
✅ **Backup security:** Encrypted backups  
✅ **Monitoring:** Security monitoring and alerting

### 5.2 Organizational Measures

✅ **Data Protection Officer (DPO):** [NAME, CONTACT]  
✅ **Staff training:** GDPR awareness training  
✅ **Access logs:** All data access logged and audited  
✅ **Incident response:** Data breach response plan  
✅ **Retention policy:** Automatic deletion after 24 months  
✅ **Privacy by design:** Pseudonymization from the start

---

## 6. Data Subject Rights

### 6.1 How are rights facilitated?

**Right to Access:**
- Customers can request information about their hashed data
- Response within 30 days

**Right to Rectification:**
- Stores can update order data
- Hashed identifiers recalculated if needed

**Right to Erasure (Right to be Forgotten):**
- Customers can request deletion
- All data associated with their hash deleted
- Response within 30 days

**Right to Object:**
- Customers can object to processing
- Data processing stopped, data deleted

**Right to Portability:**
- Customers can request their data in machine-readable format
- Provided as JSON export

**Contact for rights requests:**
- Email: [privacy@oreksi.gr]
- Address: [oreksi.gr address]

---

## 7. Retention Period

**Retention:** 24 months from last order

**Rationale:**
- Sufficient for risk assessment (delivery patterns visible in shorter period)
- Balances business needs with data minimization
- Aligns with industry standards for fraud prevention

**Deletion process:**
- Automatic deletion after 24 months
- Manual deletion on request (right to be forgotten)
- Aggregated statistics may be retained (no personal identification)

---

## 8. Third-Party Sharing

**Do we share data with third parties?**

**NO.** Data is not shared with:
- ❌ Marketing companies
- ❌ Advertising platforms
- ❌ Data brokers
- ❌ Other service providers (except infrastructure providers under DPA)

**Exception:** Infrastructure providers (hosting, database) under strict Data Processing Agreements.

---

## 9. Conclusion

### 9.1 Summary

✅ **Purpose:** Legitimate (fraud prevention, delivery optimization)  
✅ **Necessity:** Yes (cross-shop analysis requires data pooling)  
✅ **Balancing:** Legitimate interests outweigh minimal impact  
✅ **Safeguards:** Technical and organizational measures in place  
✅ **Rights:** Data subject rights facilitated  
✅ **Compliance:** GDPR compliant

### 9.2 Recommendation

**APPROVED.** This processing activity can proceed under **Legitimate Interest** (GDPR Article 6(1)(f)), provided that:

1. ✅ Privacy policies are updated and transparent
2. ✅ Data Processing Agreements are in place with all stores
3. ✅ Retention policy is enforced (24 months)
4. ✅ Data subject rights are respected
5. ✅ Regular reviews conducted (annually)

### 9.3 Review Schedule

- **Next review:** [DATE + 1 YEAR]
- **Trigger events for review:**
  - Changes to processing activities
  - New data types collected
  - Regulatory changes
  - Data breach incidents

---

## Signatures

**Assessed By:**
- Name: _______________________
- Title: _______________________
- Date: _______________________
- Signature: _______________________

**Approved By:**
- Name: _______________________
- Title: _______________________
- Date: _______________________
- Signature: _______________________

---

**Document Version:** 1.0  
**Last Updated:** [DATE]  
**Next Review:** [DATE + 1 YEAR]

