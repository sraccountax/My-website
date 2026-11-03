<?php
declare(strict_types=1);

/**
 * Tegh R123 everyday-language layer for Tegh Assist.
 *
 * Business owners rarely use accounting vocabulary: they ask "who owes me
 * money?", not "open the receivable ageing". This module rewrites common
 * shorthand, protects ordinary English words from spelling "correction", and
 * maps everyday phrasing to registered action IDs. It never executes anything:
 * the registry, permissions, parameter validation and confirmations stay
 * authoritative.
 */

/** Shorthand and contractions rewritten before normalization. */
function tegh_assist_lay_rewrite(string $text): string
{
    $t=mb_strtolower($text);
    $t=str_replace(['’','‘','“','”'],["'","'",'"','"'],$t);
    $t=(string)preg_replace(
        ["/\bwon't\b/u","/\bcan't\b/u","/\b(\w+)n't\b/u","/\bi'm\b/u","/\b(\w+)'re\b/u","/\b(\w+)'ve\b/u","/\b(\w+)'ll\b/u","/\b(what|who|where|how|that|it|there)'s\b/u","/\b(\w+)'d\b/u"],
        ['will not','cannot','$1 not','i am','$1 are','$1 have','$1 will','$1 is','$1 would'],$t);
    // Apostrophes dropped while typing: "hasnt", "dont", "whos".
    $t=(string)preg_replace(['/\b(has|have|did|does|do|is|was|were|are|could|would|should)nt\b/u','/\bwont\b/u','/\bcant\b/u','/\bwhos\b/u','/\bwhats\b/u','/\bim\b/u'],['$1 not','will not','cannot','who is','what is','i am'],$t);
    $map=[
        '/\bp\s*(?:&|and|n)\s*l\b|\bpnl\b|\bp\/l\b|\bprofits?\s*(?:n|&|\+|and|or)\s*loss(?:es)?\b/u'=>' profit and loss ',
        '/\bb\/s\b/u'=>' balance sheet ',
        '/\ba\/r\b/u'=>' accounts receivable ',
        '/\ba\/p\b/u'=>' accounts payable ',
        '/\bt\/b\b/u'=>' trial balance ',
        '/\bg\/l\b/u'=>' general ledger ',
        '/\bj\/e\b/u'=>' journal entry ',
        '/\btxns?\b|\btrxs?\b|\btrans\b/u'=>' transactions ',
        '/\bstmts?\b/u'=>' statement ',
        '/\baccts?\b/u'=>' account ',
        '/\byrs?\b/u'=>' year ',
        '/\bqtrs?\b/u'=>' quarter ',
        '/\bmos?\b(?=\s|$)/u'=>' month ',
        '/\bwks?\b/u'=>' week ',
        '/\be-?transfers?\b|\binterac\b/u'=>' payment ',
        '/\bpaycheques?\b|\bpaychecks?\b|\bpay ?stubs?\b|\bwages\b|\bsalar(?:y|ies)\b/u'=>' payroll ',
        '/\bbank rec\b/u'=>' bank reconciliation ',
        '/\bforcast\b|\bforecasts\b|\bforcasting\b|\bforecasting\b/u'=>' forecast ',
    ];
    foreach($map as $pattern=>$replacement)$t=(string)preg_replace($pattern,$replacement,$t);
    $numbers=['one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,'eight'=>8,'nine'=>9,'ten'=>10,'eleven'=>11,'twelve'=>12];
    $t=(string)preg_replace_callback('/\b(one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve)\s+(days?|weeks?|months?|years?|quarters?)\b/u',static fn(array $m):string=>$numbers[$m[1]].' '.$m[2],$t);
    return trim((string)preg_replace('/\s+/u',' ',$t));
}

/** Words that must never be spelling-"corrected" into accounting terms. */
function tegh_assist_common_words(): array
{
    static $words=null;if($words!==null)return $words;
    $list='about after again also always amount amounts anything anyone around available away back been before behind being below best better between bills bought bring brought business busy call came cannot cash change charge charged cheap check cheque cheques clear client clients close closed closing coming companies company correct cost costs could current currently customer customers data days deposit deposits does doing done down each earn earned earning earnings early employees enough equipment every everything expenses fast find first from gave give given going good got have having help here hire hired hours income into just keep kind know last late later latest less like list little look looking lose losing lost made make makes making many maybe mean means money month months more most much need needs never next nothing office only other owed owes owing paid pays paying people period person please pretty profit profits quick quickly really received receive recent right running same says sell selling sent send should show since sold some someone something soon spend spending spent staff start still such sure take team than thank thanks that their them then there these they thing things think this those through time today told total totals under until upon used very want wants week weeks were what when where which while whole will with within without work worker workers worth would year years yesterday your yours tomorrow tonight supplies supply supplier suppliers vendor vendors agree agrees owner owners partner accountant bookkeeper password invite access lock locked unlock backup restore owns spend afford supposed collect collected collecting charged outstanding unpaid behind performing performed doing going gone insurance prepaid consulting electricity rent utilities fuel gas travel meals phone internet software subscription subscriptions photo picture receipt receipts logo someone access hire hiring worker profit loss revenue sales costs spend owed spent supposed meant stuff thing things hello hiya thank thanks okay whatever something anything nothing everything kind sort blah random';
    $words=array_fill_keys(preg_split('/\s+/',$list,-1,PREG_SPLIT_NO_EMPTY)?:[],true);
    return $words;
}

/** Filler words ignored when measuring how well a request covers an action. */
function tegh_assist_stop_words(): array
{
    static $words=null;if($words!==null)return $words;
    $list='the and for you your our my me are was can how what which who whom does did have has had that this these those with from about please want need like would could should some any all get give tell let see know there here into out just also its it is am be do of to in on at an we us them they will not has been being very really much many more most i a';
    return $words=array_fill_keys(preg_split('/\s+/',$list,-1,PREG_SPLIT_NO_EMPTY)?:[],true);
}

/**
 * Everyday phrasing → registered action. Each rule is
 * [pattern, exclusion pattern or '', preferred action IDs in order, score].
 * The first preferred action present in the caller's registry receives the score.
 */
function tegh_assist_intent_rules(): array
{
    $vendor='(?:vendors?|suppliers?|bills?)';$customer='(?:customers?|clients?)';
    return [
        // R143 (DEF-12): everyday ways of asking for the bank balance were not recognised.
        ['/\bhow much (?:money|cash) (?:is |do (?:i|we) have |have (?:i|we) got )?(?:left |still )?(?:in|at) (?:the |my |our )?(?:bank|chequing|checking|savings|accounts?)\b|\b(?:cash|money) (?:in|at) (?:the |my |our )?bank\b|\bbank (?:balances?|account balances?)\b|\bwhat(?:\'s| is) in (?:my|our|the) (?:bank|chequing|checking|savings)(?: account)?s?\b|\bhow much (?:money|cash) do (?:i|we) have\b/u','/\b(?:forecast|future|next|run out|flow|where|owe|owed|owing|agree|match\w*|reconcil\w*|books?|difference)\b/u',['nav.bank_accounts'],.94],
        // Money in
        ['/\b(?:who|which ' . $customer . '|what ' . $customer . ')\b.{0,30}\b(?:owe|owes|owing)\b|\b(?:owe|owes|owing|owed) (?:me|us)\b|\b(?:owed|owing|due) to (?:me|us)\b|\b(?:outstanding|unpaid|open) (?:customer |sales )?(?:invoices|receivables)\b|\b(?:accounts receivable|receivables?)\b(?! ledger)/u','/\b(?:overdue|late|not paid|yet to pay)\b|\b(?:do|should|must|did) (?:i|we) (?:owe|pay)\b|\b(?:i|we) owe\b/u',['report.ar_aging'],.93],
        ['/\b(?:has|have|did|do|does) not (?:yet )?(?:paid|pay)\b|\byet to pay\b|\b(?:late|overdue|slow|behind)\b.{0,25}\b(?:pay|paying|payments?|payers?|' . $customer . '|invoices?)\b|\b' . $customer . '\b.{0,25}\b(?:late|overdue|behind)\b|\boverdue (?:invoices|receivables)\b|\bchase\b.{0,20}\b(?:payment|money|' . $customer . ')\b/u','/\b(?:vendors?|suppliers?|bills?|i owe|we owe|payable)\b/u',['receivables.overdue','report.ar_aging'],.93],
        ['/\b(?:money|cash|payments?) (?:coming in|expected|due in|to collect|to come in)\b|\bexpected (?:collections|receipts|payments)\b|\bwho (?:will|is going to|should) pay (?:me|us)\b|\bcollections? (?:this|next)\b/u','/\b(?:going out|and out|out and)\b/u',['receivables.expected_collections'],.93],
        ['/\b(?:' . $customer . '|someone) (?:has |just )?(?:paid|sent|gave|transferred)\b.{0,15}\b(?:me|us|money|payment)?|\b(?:got|received|receive|record|recording|enter|log) (?:a |the )?(?:payment|money|cheque|check|deposit)s? from\b|\bgot paid\b|\bcustomer (?:payment|receipt)s?\b|\breceive (?:a )?payment\b|\bpayment received\b|\bmoney received\b/u','/\b(?:vendors?|suppliers?|history|list|report)\b/u',['nav.customer_payments','payment.customer'],.93],
        ['/\b(?:bill|charge|invoice) (?:a |my |the |this |our )?' . $customer . '\b|\bsend (?:out )?(?:an? |the )?invoice\b|\b(?:new|create|make|raise|issue|write|prepare|start|do) (?:a |an |new )*(?:sales |customer )?invoice\b|\binvoice (?:a |my |the )?' . $customer . '\b/u','/\b(?:vendors?|suppliers?|from|received|got|purchase)\b/u',['nav.customer_invoice_create','invoice.create'],.92],
        // A name after the word ("create customer Acme Rentals") keeps the name-prefilling action.
        ['/\b(?:add|new|create|set ?up|enter|register) (?:a |an )?(?:new )?' . $customer . '\s+(?:called |named )?(?!list|record|account|profile|form|for\b|to\b|in\b|please\b)[a-z0-9&]/u','/\b(?:invoice|payment)\b/u',['customer.create','nav.customer_create'],.93],
        ['/\b(?:add|new|create|set ?up|enter|register) (?:a |an )?(?:new )?' . $customer . '\b/u','/\b(?:invoice|payment)\b/u',['nav.customer_create','customer.create'],.92],
        ['/\b' . $customer . ' (?:list|register|directory|names|contacts)\b|\b(?:list|all|see|show)(?: of| me)? (?:my |our |the )?' . $customer . '\b/u','/\b(?:owe|overdue|late|balances?|invoices?|payments?|ledger)\b/u',['nav.customers'],.92],
        ['/\b' . $customer . ' (?:balances?|summary)\b|\bbalances? (?:by|per|for each) ' . $customer . '\b/u','',['report.customer_balances'],.92],
        ['/\b(?:sales|customer) invoices?\b.{0,15}\b(?:list|register|all)\b|\b(?:list|all|see|show)(?: of| me)? (?:my |our |the )?(?:sales |customer )?invoices\b/u','/\b(?:vendors?|suppliers?|bills?|overdue|late|create|new)\b/u',['nav.customer_invoices'],.9],
        ['/\b(?:statement|ledger|history|activity) (?:for|of) (?:a |my |the |each )?' . $customer . '\b|\b' . $customer . ' (?:ledger|history|activity|statement)s?\b/u','',['nav.customer_ledgers'],.9],
        // Money out
        ['/\b(?:how much|what) (?:money )?(?:do|does|did) (?:i|we) owe\b|\b(?:money|amounts?) (?:i|we) owe\b|\bwho (?:do|should) (?:i|we) (?:owe|pay)\b|\bwhat (?:i|we) owe\b|\b(?:accounts payable|payables?)\b(?! ledger)|\b(?:unpaid|open|outstanding) (?:bills|vendor invoices|supplier invoices|payables)\b/u','/\b(?:own and owe|own|gst|hst|pst|tax|cra|payroll|overdue|late|this week|next week|coming due|due soon|upcoming)\b/u',['report.ap_aging'],.93],
        ['/\b(?:bills?|invoices?|payments?) (?:coming|falling|that are) due\b|\b(?:bills?|payments?) (?:due|to pay|to make)\b|\bwhat (?:bills? )?(?:do|should|must|am|are) (?:i|we) (?:need to |have to |supposed to |going to |meant to )?pay\b|\bupcoming (?:bills|payments)\b|\bdue (?:soon|this week|next week|this month)\b/u','/\b(?:overdue|late|history|' . $customer . ')\b/u',['payables.upcoming'],.93],
        ['/\b(?:overdue|late|past due)\b.{0,20}\b(?:bills?|' . $vendor . '|payables?)\b|\b(?:bills?|' . $vendor . ')\b.{0,20}\b(?:overdue|late|past due)\b/u','',['payables.overdue'],.93],
        ['/\b(?:got|received|have|enter|record|add|new|create|log|input) (?:a |an |the )?(?:new )?(?:bill|invoice|receipt)s? (?:from|for) (?:a |my |the |our )?' . $vendor . '?|\b(?:vendor|supplier|purchase) (?:bill|invoice)\b|\b(?:enter|add|record|new|create|log) (?:a |an )?(?:new )?bills?\b/u','/\b(?:pay|paid|payment|list|register|all|overdue|history)\b/u',['nav.vendor_invoice_create','bill.create'],.93],
        ['/\bpay (?:a |my |the |our |off )?(?:' . $vendor . '|invoices? from)\b|\b(?:i|we) (?:have )?paid (?:a |my |the |our )?' . $vendor . '\b|\b(?:vendor|supplier|bill) payments?\b|\brecord (?:a )?payment to\b|\bpay (?:the |my |a |our |this |that )?(?:[a-z]+ )?bills?\b/u','/\b(?:history|list|report|made|past)\b/u',['nav.vendor_payments','payment.vendor'],.93],
        ['/\b(?:vendor|supplier) payment history\b|\bpayments? (?:i|we) (?:made|sent)\b|\bwhat (?:did|have) (?:i|we) paid?\b|\bpast payments? to\b|\bpayment history\b/u','/\b' . $customer . '\b/u',['payables.payment_history'],.93],
        ['/\b(?:add|new|create|set ?up|enter|register) (?:a |an )?(?:new )?(?:vendor|supplier)\s+(?:called |named )?(?!list|record|account|profile|form|for\b|to\b|in\b|please\b)[a-z0-9&]/u','/\b(?:invoice|bill|payment)\b/u',['vendor.create','nav.vendor_create'],.93],
        ['/\b(?:add|new|create|set ?up|enter|register) (?:a |an )?(?:new )?(?:vendor|supplier)s?\b/u','/\b(?:invoice|bill|payment)\b/u',['nav.vendor_create','vendor.create'],.92],
        ['/\b(?:vendor|supplier)s? (?:list|register|directory|names|contacts)\b|\b(?:list|all|see|show)(?: of| me)? (?:my |our |the )?(?:vendors|suppliers)\b/u','/\b(?:owe|overdue|balances?|payments?|ledger)\b/u',['nav.vendors'],.92],
        ['/\b(?:vendor|supplier)s? (?:balances?|summary)\b/u','',['report.vendor_balances'],.92],
        ['/\b(?:vendor|supplier) invoices?\b.{0,15}\b(?:list|register|all)\b|\b(?:list|all|see|show)(?: of| me)? (?:my |our |the )?bills\b/u','/\b(?:overdue|late|create|new|enter|add)\b/u',['nav.vendor_invoices'],.9],
        // Reports
        ['/\b(?:am i|are we|is (?:my|the|our) (?:business|company)) (?:making|earning|losing|turning a)\b|\b(?:make|made|making) (?:a |any )?(?:profit|money)\b|\bhow much (?:profit|money)\b(?!.{0,30}\b(?:owe|owed|owing|due|have|left|in the bank)\b)|\b(?:profit|profits|income|earnings|revenue|sales)\b.{0,25}\b(?:and|vs|versus|minus|less) (?:expenses?|costs?|spending)\b|\bprofit and loss\b|\bincome statement\b|\bnet (?:income|profit|loss)\b|\bhow (?:is|are|did|has|have|was|were) (?:my|our|the) (?:business|company|sales|year|month|quarter) (?:do|doing|done|did|perform|performing|performed|going|gone|went)\b|\b(?:did|do) (?:i|we) (?:make|lose) money\b|\bbottom line\b|\b(?:my|our|the) (?:profit|profits|net income|earnings|margins?)\b/u','/\b(?:forecast|future|next)\b/u',['report.profit_loss'],.94],
        ['/\bwhat (?:do )?(?:i|we) own and (?:what (?:i|we) )?owe\b|\bown and owe\b|\bnet worth\b|\bassets? and liabilit|\bfinancial position\b|\bbalance sheet\b|\bwhat is (?:my|the|our) (?:business|company) worth\b/u','',['report.balance_sheet'],.94],
        ['/\bwhere (?:did|does|is) (?:all )?(?:my|our|the) (?:cash|money) (?:go|going|come from)\b|\bcash ?flow\b|\bcash (?:in and out|movement|coming in and going out)\b|\bmoney in and (?:money )?out\b/u','/\bforecast|\bfuture\b|\bnext\b|\brun out\b/u',['report.cash_flow'],.93],
        ['/\b(?:run|running|ran) (?:out|low) (?:of|on) (?:cash|money)\b|\benough (?:cash|money)\b|\bcash (?:forecast|projection|outlook|position next)\b|\bforecast\b|\bproject(?:ed|ion)?\b.{0,12}\bcash\b|\bcash\b.{0,25}\bnext \d+ (?:days|weeks|months)\b|\bfuture cash\b|\bcan (?:i|we) afford\b|\bhow much cash will\b/u','',['analysis.cash_forecast'],.93],
        ['/\b(?:gst|hst|pst|qst|sales tax|tax)\b.{0,30}\b(?:owe|owing|report|summary|collect\w*|charged|paid|return|remit|due|payable|position)\b|\b(?:collect\w*|charged|paid|owe)\b.{0,20}\b(?:gst|hst|pst|sales tax)\b|\bhow much (?:gst|hst|pst|sales tax|tax)\b|\b(?:gst|hst) (?:return|report)\b/u','/\b(?:payroll|source deduction|employee|income tax slip|settings?|rates?|set ?up)\b/u',['report.tax_summary'],.94],
        ['/\btrial balance\b|\bdebits? and credits?\b/u','',['report.trial_balance','nav.trial_balance'],.93],
        ['/\bbudget\b.{0,15}\b(?:vs|versus|against|compared|actual)\b|\b(?:over|under) budget\b|\bactual (?:vs|versus|against) budget\b/u','',['report.budget_actual','analysis.budget_variance'],.93],
        ['/\b(?:duplicat\w*|doubled?|repeated)\b.{0,30}\b(?:transactions?|bank|entries|lines|imports?)\b|\bimported twice\b/u','',['bank.duplicates.find'],.93],
        ['/\b(?:where|what) (?:did|do) (?:i|we) spend\b|\bexpense (?:report|register|list)\b|\blist (?:of )?(?:my )?expenses\b|\bspending (?:report|summary)\b/u','',['report.expense_register'],.92],
        ['/\b(?:general ledger|gl) (?:detail|report)\b|\ball (?:posted )?(?:entries|transactions) (?:by|per) account\b/u','',['report.general_ledger_detail'],.9],
        ['/\b(?:currency|exchange|foreign|usd)\b.{0,20}\b(?:exposure|risk|report)\b/u','',['report.currency_exposure'],.9],
        // Banking
        ['/\b(?:upload|import|load|bring in|add|pull in|send) (?:in )?(?:my |a |the |our )?(?:bank |credit card )?(?:statements?|csv|bank transactions|bank file|ofx|qbo|qfx|bank data)\b|\bimport (?:from )?(?:my )?bank\b/u','',['nav.bank_import'],.94],
        ['/\bmatch (?:my |the |our )?(?:bank )?(?:transactions|deposits|payments|lines|entries)\b|\b(?:categori[sz]e|review|sort|code|classify) (?:my |the |our )?(?:bank )?(?:transactions|spending|lines)\b|\bbank feed\b|\bunmatched (?:bank )?transactions\b/u','',['nav.bank_review','bank.transactions.match_post'],.93],
        ['/\breconcile\b|\breconciliation\b|\b(?:bank|statement)\b.{0,25}\b(?:agree|agrees|match|matches|tie|ties|balance with)\b.{0,25}\bbooks?\b|\bbooks?\b.{0,25}\b(?:agree|agrees|match|matches|tie|ties)\b.{0,25}\bbank\b/u','/\b(?:agent|report|summary|history)\b/u',['nav.reconciliation','bank.reconciliation.open'],.93],
        ['/\b(?:reconciliation|reconciled) (?:report|summary|history)\b/u','',['report.bank_reconciliation_summary'],.93],
        ['/\btransfer (?:money |cash |funds )?(?:between|from|to)\b|\bmove (?:money|cash|funds) (?:between|from|to)\b/u','',['nav.bank_transfers'],.93],
        ['/\bbank (?:accounts?|balances?)\b|\bcredit cards? accounts?\b|\badd (?:a )?(?:bank|credit card)(?: account)?\b|\bhow much (?:cash|money) (?:do )?(?:i|we) have\b/u','/\b(?:statements?|import|upload|reconcil\w*|transactions?|transfer|agree\w*|match\w*|tie|books?)\b/u',['nav.bank_accounts'],.9],
        ['/\bbank transactions? (?:report|list)\b|\blist (?:of )?(?:my )?bank transactions\b/u','',['report.bank_transactions','nav.bank_transactions'],.9],
        // Payroll
        ['/\b(?:run|do|process|start|prepare) (?:the |a |this weeks? |this months? )?payroll\b|\bpay (?:my |the |our |all )?(?:employees|staff|team|workers|people)\b|\bpay ?run\b|\bpayroll (?:run|for)\b/u','/\b(?:remit|remittance|history|report|rates?|settings?)\b/u',['payroll.run.start','nav.payroll_runs'],.94],
        ['/\b(?:add|hire|hired|new|create|set ?up|onboard) (?:a |an )?(?:new )?(?:employee|staff member|worker|team member|hire)\b|\bhired (?:someone|a person)\b|\bemployee list\b|\blist (?:of )?(?:my )?employees\b/u','',['nav.payroll_employees'],.94],
        ['/\bsource deductions?\b|\bpayroll (?:remittance|taxes|deductions)\b|\bremit (?:payroll|to cra)\b|\bcra (?:remittance|payment)\b|\bpd7a\b/u','',['nav.payroll_remittance'],.94],
        ['/\bpayroll (?:history|report|register)\b|\bpast (?:pay ?runs|payrolls)\b/u','',['report.pay_run_register','nav.payroll_history'],.92],
        ['/\bcalculate (?:a )?(?:pay|payroll|net pay|deductions)\b|\bnet pay\b|\btake home pay\b/u','',['nav.payroll_calculator','payroll.calculate'],.92],
        // General ledger and setup
        ['/\b(?:record|enter|add|log|input) (?:an? |my |the )?(?:expense|receipt|purchase|spending)s?\b|\b(?:i|we) (?:bought|purchased|spent)\b|\bpaid (?:for|with) (?:cash|debit|my (?:own|personal))\b/u','/\b(?:bank|statement|payroll|bill from|invoice from|from (?:a |my |the |our )?(?:supplier|vendor)s?)\b/u',['nav.expense_vouchers'],.93],
        ['/\b(?:make|create|record|enter|add|post|write|prepare|do) (?:an? |the )?(?:manual |adjusting )?(?:journal entry|journal|adjusting entry|adjustment|je)\b|\bjournal entry\b/u','/\b(?:list|register|history|all)\b/u',['journal.prepare','nav.journal_create'],.93],
        ['/\b(?:list|all|show)(?: of)? (?:my |the |our )?accounts\b|\bchart of accounts\b|\bcoa\b|\badd (?:a |an )?(?:new )?(?:gl |ledger )?account\b/u','/\b(?:bank|credit card|user)\b/u',['nav.chart_of_accounts'],.93],
        ['/\b(?:products?|services?|items?) (?:i|we) (?:sell|offer|provide)\b|\bprice list\b|\b(?:add|new|create) (?:a |an )?(?:product|service|item)\b|\bproducts? and services?\b|\bmy (?:products|services|items)\b/u','',['nav.products_services'],.93],
        ['/\bback ?(?:up|ups)\b|\bexport (?:all )?(?:of )?(?:my |our )?(?:data|books|everything)\b|\brestore\b|\bsave a copy\b/u','',['nav.backup'],.94],
        ['/\b(?:close|closing|finish|wrap up) (?:the |my |our |this |last )?(?:month|books|period|year)\b|\bmonth ?end\b|\byear ?end (?:close|checklist)\b/u','/\block\b/u',['nav.month_end_close','month_end.checks'],.93],
        ['/\block\b.{0,25}\b(?:period|year|month|books|dates?)\b|\b(?:stop|prevent) (?:changes|edits|editing)\b|\bperiod lock/u','',['nav.period_locking'],.94],
        ['/\b(?:change|reset|update|forgot) (?:my )?password\b|\bmy (?:profile|account|login|email)\b|\btwo factor\b|\b2fa\b/u','',['nav.my_account'],.94],
        ['/\b(?:invite|add|give|grant|share (?:my )?books with)\b.{0,30}\b(?:users?|accountant|bookkeeper|team ?mates?|partner|colleague)s?\b|\b(?:accountant|bookkeeper|partner|colleague|someone|staff)\b.{0,25}\baccess\b|\bpermissions?\b|\buser (?:access|roles?)\b/u','/\b(?:employee|payroll|hire)\b/u',['nav.users'],.94],
        ['/\bdepreciat|\bfixed assets?\b|\b(?:equipment|vehicles?|computers?|furniture|machinery)\b.{0,25}\b(?:register|list|bought|buy|purchased|add|record)\b|\b(?:bought|purchased|buy) (?:a |an |new )?(?:truck|car|vehicle|computer|laptop|equipment|machine|furniture)\b/u','',['nav.fixed_assets','report.fixed_assets'],.93],
        ['/\bopening balances?\b|\bstarting balances?\b|\bbeginning balances?\b|\bbalances? (?:from|when) (?:i|we) (?:started|switched)\b/u','',['nav.opening_balances'],.93],
        ['/\btax (?:settings|rates|codes)\b|\bset ?up (?:gst|hst|pst|sales tax|tax)\b|\b(?:gst|hst) (?:number|registration)\b/u','',['nav.tax_settings'],.93],
        ['/\binvoice (?:template|design|layout|logo|look|style)s?\b|\b(?:add|change|upload) (?:my |our |a )?logo\b|\bcustomi[sz]e (?:my |the )?invoices?\b/u','',['nav.invoice_templates'],.93],
        ['/\b(?:company|business) (?:details|info|information|name|address|profile)\b|\bfiscal year end\b/u','',['nav.company_details'],.9],
        ['/\brecurring\b.{0,20}\b(?:invoices?|transactions?|entries)\b|\b(?:repeat|automatic|every month) (?:invoice|bill|entry)\b/u','/\b(?:vendor|supplier|bill)s?\b/u',['nav.recurring_transactions'],.9],
        ['/\brecurring\b.{0,20}\b(?:bills?|vendor|supplier)\b/u','',['nav.recurring_vendor_bills'],.92],
        ['/\bbudgets?\b/u','/\b(?:vs|versus|actual|against|compared|over|under|variance)\b/u',['nav.budgets'],.9],
        ['/\b(?:foreign|exchange|currency) rates?\b|\bexchange rates?\b/u','',['nav.currencies'],.92],
        ['/\bimport (?:my |a list of |the )?' . $customer . '\b/u','',['nav.import_customers'],.93],
        ['/\bimport (?:my |a list of |the )?(?:vendors|suppliers)\b/u','',['nav.import_vendors'],.93],
        ['/\bimport (?:my |a list of |the )?(?:products|services|items)\b/u','',['nav.import_products'],.93],
        ['/\baudit (?:trail|history|log)\b|\bwho (?:changed|edited|deleted)\b/u','',['nav.audit_history'],.93],
        ['/\b(?:scan|upload|snap|photo of) (?:a |my )?(?:receipt|bill|invoice|document)s?\b/u','',['nav.document_intake'],.92],
        ['/\bday ?book\b|\bevery(?:thing)? (?:i|we) (?:posted|entered)\b|\ball (?:posted )?entries\b/u','',['nav.day_book'],.9],
        ['/\bgifi\b|\bt2 (?:mapping|schedule)\b/u','',['nav.gifi_report'],.93],
    ];
}

/** @return array<string,float> action_id => score for everyday phrasing. */
function tegh_assist_intent_scores(string $normalized,array $registry): array
{
    $text=' '.$normalized.' ';$scores=[];$claimed=[];
    foreach(tegh_assist_intent_rules() as [$pattern,$exclude,$ids,$score]){
        // The first matching rule for a group of actions wins; a later, more
        // general rule for the same actions must not create a near-tie.
        if(array_intersect($ids,$claimed))continue;
        if(!preg_match($pattern,$text))continue;
        if($exclude!==''&&preg_match($exclude,$text))continue;
        array_push($claimed,...$ids);
        foreach($ids as $id){if(isset($registry[$id])){$scores[$id]=max($scores[$id]??0.0,(float)$score);break;}}
    }
    return $scores;
}

/**
 * Everyday date phrases → a concrete period. Returns [start,end,label] or
 * null when the request names no period this layer understands.
 * @return array{0:string,1:string,2:string}|null
 */
function tegh_assist_period_phrase(string $text,array $company,string $today,bool $forward=false): ?array
{
    $now=new DateTimeImmutable($today,new DateTimeZone('UTC'));$t=' '.mb_strtolower($text).' ';
    $fmt=static fn(DateTimeImmutable $d):string=>$d->format('Y-m-d');
    $months=['january'=>1,'jan'=>1,'february'=>2,'feb'=>2,'march'=>3,'mar'=>3,'april'=>4,'apr'=>4,'may'=>5,'june'=>6,'jun'=>6,'july'=>7,'jul'=>7,'august'=>8,'aug'=>8,'september'=>9,'sep'=>9,'sept'=>9,'october'=>10,'oct'=>10,'november'=>11,'nov'=>11,'december'=>12,'dec'=>12];
    if(preg_match('/\b(?:next|coming|upcoming|following)\s+(\d{1,3})\s+(day|week|month|year)s?\b/u',$t,$m)){
        $n=max(1,min(260,(int)$m[1]));$end=match($m[2]){'day'=>$now->modify('+'.($n-1).' days'),'week'=>$now->modify('+'.($n*7-1).' days'),'month'=>$now->modify('+'.$n.' months')->modify('-1 day'),default=>$now->modify('+'.$n.' years')->modify('-1 day')};
        return [$today,$fmt($end),'Next '.$n.' '.$m[2].($n===1?'':'s')];
    }
    if(preg_match('/\b(?:last|past|previous|prior|trailing)\s+(\d{1,3})\s+(day|week|month|year)s?\b/u',$t,$m)){
        $n=max(1,min(260,(int)$m[1]));$start=match($m[2]){'day'=>$now->modify('-'.($n-1).' days'),'week'=>$now->modify('-'.($n*7-1).' days'),'month'=>$now->modify('-'.$n.' months')->modify('+1 day'),default=>$now->modify('-'.$n.' years')->modify('+1 day')};
        return [$fmt($start),$today,'Last '.$n.' '.$m[2].($n===1?'':'s')];
    }
    $weekStart=$now->modify('monday this week');
    if(preg_match('/\bthis week\b/u',$t))return $forward?[$today,$fmt($weekStart->modify('+6 days')),'This week']:[$fmt($weekStart),$today,'This week'];
    if(preg_match('/\b(?:last|previous|past) week\b/u',$t))return [$fmt($weekStart->modify('-7 days')),$fmt($weekStart->modify('-1 day')),'Last week'];
    if(preg_match('/\bnext week\b/u',$t))return [$fmt($weekStart->modify('+7 days')),$fmt($weekStart->modify('+13 days')),'Next week'];
    if(preg_match('/\bnext month\b/u',$t)){$start=$now->modify('first day of next month');return [$fmt($start),$fmt($start->modify('last day of this month')),'Next month'];}
    if(preg_match('/\bnext quarter\b/u',$t)){$q=(int)floor(((int)$now->format('n')-1)/3);$start=$now->setDate((int)$now->format('Y'),$q*3+1,1)->modify('+3 months');return [$fmt($start),$fmt($start->modify('+3 months')->modify('-1 day')),'Next quarter'];}
    if(preg_match('/\b(?:last|previous|prior) (?:calendar )?year\b/u',$t)){[$s,$e]=tegh_report_period_preset($company,'previous_year',$today);return [$s,$e,'Last year ('.substr($s,0,4).')'];}
    if(preg_match('/\b(?:this|current) year\b|\byear to date\b|\bytd\b|\bso far this year\b/u',$t)){[$s,$e]=tegh_report_period_preset($company,'fiscal_current',$today);return [$s,$e,'Year to date'];}
    if(preg_match('/\bq([1-4])(?:\s+(?:of\s+)?(\d{4}))?\b|\b(first|second|third|fourth) quarter(?: of)?(?:\s+(\d{4}))?\b/u',$t,$m)){
        $q=$m[1]!==''?(int)$m[1]:['first'=>1,'second'=>2,'third'=>3,'fourth'=>4][$m[3]];$year=(int)(($m[2]??'')!==''?$m[2]:(($m[4]??'')!==''?$m[4]:$now->format('Y')));
        $start=new DateTimeImmutable(sprintf('%04d-%02d-01',$year,($q-1)*3+1),new DateTimeZone('UTC'));if(($m[2]??'')===''&&($m[4]??'')===''&&$start>$now)$start=$start->modify('-1 year');
        $end=$start->modify('+3 months')->modify('-1 day');return [$fmt($start),$fmt($end),'Q'.$q.' '.$start->format('Y')];
    }
    if(preg_match('/\b(january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sept|sep|oct|nov|dec)\b(?:\s+(\d{4}))?/u',$t,$m)&&!($m[1]==='may'&&!preg_match('/\b(?:in|for|of|during)\s+may\b|\bmay\s+\d{4}\b/u',$t))&&!($m[1]==='mar'&&!isset($m[2]))){
        $month=$months[$m[1]];$year=isset($m[2])&&$m[2]!==''?(int)$m[2]:(int)$now->format('Y');
        $start=new DateTimeImmutable(sprintf('%04d-%02d-01',$year,$month),new DateTimeZone('UTC'));if((!isset($m[2])||$m[2]==='')&&$start>$now)$start=$start->modify('-1 year');
        return [$fmt($start),$fmt($start->modify('last day of this month')),$start->format('F Y')];
    }
    if(preg_match('/\b(?:in|for|during|fy|fiscal|year)\s*(20\d{2})\b|\b(20\d{2})\b(?!-)/u',$t,$m)){$year=(int)(($m[1]??'')!==''?$m[1]:$m[2]);return [sprintf('%04d-01-01',$year),sprintf('%04d-12-31',$year),(string)$year];}
    return null;
}

/**
 * Sensible default period for a read-only request that names no dates, so an
 * everyday question runs in one step. The label says it is a default; the
 * dates stay editable in the review form.
 * @return array{0:string,1:string,2:string}|null
 */
function tegh_assist_default_period(string $actionId,array $company,string $today): ?array
{
    $now=new DateTimeImmutable($today,new DateTimeZone('UTC'));
    return match($actionId){
        'report.profit_loss','report.trial_balance','report.cash_flow'=>(static function()use($company,$today):array{[$s,$e]=tegh_report_period_preset($company,'fiscal_current',$today);return [$s,$e,'Year to date'];})(),
        'analysis.cash_forecast'=>[$today,$now->modify('+3 months')->modify('-1 day')->format('Y-m-d'),'Next 3 months'],
        'receivables.expected_collections','payables.upcoming'=>[$today,$now->modify('+29 days')->format('Y-m-d'),'Next 30 days'],
        'payables.payment_history'=>[$now->modify('-89 days')->format('Y-m-d'),$today,'Last 90 days'],
        default=>null,
    };
}

/**
 * Plain-language answers to common small-business questions. Answers are
 * general guidance only; each points to the Tegh screen that does the work and
 * says when to confirm with CRA or an accountant.
 * @return array<string,mixed>|null
 */
function tegh_assist_plain_answer(string $question): ?array
{
    $q=' '.tegh_assist_lay_rewrite($question).' ';
    $check='General guidance only. Confirm your own situation with CRA or your accountant before relying on it.';
    $topics=[
        ['/\b(?:register|registration|sign up|need|have)\b.{0,30}\b(?:gst|hst)\b|\b(?:gst|hst)\b.{0,30}\b(?:register|registration|threshold|small supplier)\b|\bsmall supplier\b/u',
         'Most businesses must register for GST/HST once taxable sales pass $30,000 in a single calendar quarter or over the last four consecutive calendar quarters (the "small supplier" limit). You may register voluntarily below that, which lets you claim input tax credits on business purchases but means you must charge GST/HST on your sales.',
         [['Set up tax','Record your GST/HST number and tax rates in Tax Settings.','nav.tax_settings'],['Check your position','The Tax Summary shows GST/HST collected and paid.','report.tax_summary']]],
        ['/\b(?:car|vehicle|truck|van|mileage|kilomet(?:er|re)s?|gas|fuel)\b.{0,40}\b(?:deduct\w*|claim\w*|expense\w*|write off|business use)\b|\b(?:deduct\w*|claim\w*|write off)\b.{0,40}\b(?:car|vehicle|truck|van|mileage|gas|fuel)\b/u',
         'You can generally deduct the business-use share of vehicle costs (fuel, insurance, repairs, lease or capital cost allowance). The share is usually business kilometres divided by total kilometres, so keep a logbook. Personal driving, including commuting, is not business use.',
         [['Record a vehicle cost','Use Expense Vouchers for fuel or repairs paid personally or in cash.','nav.expense_vouchers'],['Vehicles you own','Add the vehicle as a fixed asset to track depreciation.','nav.fixed_assets']]],
        ['/\bhome office\b|\b(?:work|working|business) (?:from|at) home\b.{0,40}\b(?:deduct\w*|claim\w*|expense\w*)\b|\b(?:deduct\w*|claim\w*)\b.{0,30}\b(?:rent|utilities|internet)\b.{0,20}\bhome\b/u',
         'A home office can usually be claimed if it is your principal place of business, or if you use it only for the business and regularly meet clients there. The claim is normally the office area as a share of the home, applied to costs such as rent, utilities and home insurance.',
         [['Record the expense','Use Expense Vouchers for the business share.','nav.expense_vouchers']]],
        ['/\b(?:meals?|restaurant|lunch|dinner|entertainment|client lunch)\b.{0,40}\b(?:deduct\w*|claim\w*|expense\w*|write off)\b|\b(?:deduct\w*|claim\w*)\b.{0,30}\b(?:meals?|entertainment)\b/u',
         'Business meals and entertainment are generally only 50% deductible for income tax, even though the full GST/HST paid may be claimable as an input tax credit when you are registered. Keep the receipt and a note of who attended and the business purpose.',
         [['Record the meal','Use Expense Vouchers or post it from the bank feed.','nav.expense_vouchers']]],
        ['/\b(?:pay|paying) (?:my ?self|myself|the owner)\b|\bowner(?:s)? (?:draw|draws|pay|salary)\b|\bdraws?\b.{0,20}\b(?:owner|myself)\b|\bsalary (?:or|vs|versus) dividends?\b/u',
         'If you are a sole proprietor, money you take out is an owner draw, not an expense; it reduces equity. If you have a corporation, you are paid through payroll (salary) or dividends, and each has different tax and CPP effects, so decide with your accountant.',
         [['Pay yourself salary','Payroll handles salary with source deductions.','nav.payroll'],['Owner draw','Record a draw as a journal to the owner draw or equity account.','journal.prepare']]],
        ['/\b(?:how long|how many years)\b.{0,30}\b(?:keep|store|retain)\b|\b(?:keep|retain)\b.{0,20}\b(?:records|receipts|books)\b.{0,20}\b(?:how long|years)\b/u',
         'CRA generally requires business records and supporting documents to be kept for six years from the end of the tax year they relate to. Tegh keeps your entries and attached documents; a regular backup gives you your own copy.',
         [['Make a backup','Download a copy of your books.','nav.backup']]],
        ['/\binput tax credits?\b|\bitcs?\b|\bclaim (?:back )?(?:the )?(?:gst|hst)\b|\b(?:gst|hst) (?:i|we) paid\b|\bget (?:the )?(?:gst|hst) back\b/u',
         'Input tax credits (ITCs) let a GST/HST registrant recover the GST/HST paid on business purchases. They are claimed on your GST/HST return and reduce the tax you owe. Keep supplier invoices showing the supplier’s GST/HST number.',
         [['See GST/HST paid and collected','The Tax Summary shows your net position.','report.tax_summary']]],
        ['/\b(?:when|what date)\b.{0,30}\b(?:payroll|source deductions?|remittance)\b.{0,20}\bdue\b|\bpayroll (?:remittance|deductions?) due\b|\bwhen (?:do|should|must) (?:i|we) remit\b/u',
         'Most small employers (regular remitters) must send CPP, EI and income tax deductions to CRA by the 15th of the month after the pay date. Larger employers remit more often; your CRA remitter type is shown on your remittance voucher.',
         [['Record the remittance','Remittance Records fills in the amounts owing.','nav.payroll_remittance']]],
        ['/\b(?:difference between|what is the difference)\b.{0,30}\b(?:invoice|bill)\b.{0,30}\b(?:invoice|bill)\b|\bis a bill (?:the same as|an invoice)\b/u',
         'In Tegh, an invoice is what you send a customer (money in) and a bill or vendor invoice is what a supplier sends you (money out).',
         [['Create an invoice','Bill a customer.','nav.customer_invoice_create'],['Enter a bill','Record a supplier bill.','nav.vendor_invoice_create']]],
        ['/\b(?:capitali[sz]e|capital (?:asset|expense|purchase)|asset or (?:an )?expense|expense or (?:an )?asset)\b/u',
         'Purchases that last beyond the year and help earn income (equipment, vehicles, computers, furniture) are usually recorded as assets and depreciated, rather than expensed at once. Small items and repairs are normally expensed.',
         [['Add a fixed asset','Record the purchase and its depreciation.','nav.fixed_assets']]],
    ];
    foreach($topics as [$pattern,$answer,$steps]){
        if(!preg_match($pattern,$q))continue;
        return ['mode'=>'answer','answer'=>$answer,'recommendedWorkflow'=>'reports','steps'=>array_map(static fn(array $s):array=>['title'=>$s[0],'instruction'=>$s[1],'actionKey'=>$s[2]],$steps),'caution'=>$check];
    }
    return null;
}
