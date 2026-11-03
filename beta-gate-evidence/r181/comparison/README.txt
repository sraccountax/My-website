R181 safety baseline. tegh_gate-r180.sql = database snapshot (R180 installed, after the R180 full gate).
r180-a and r180-b = two captures of the same R180 install (62 Canadian companies, 9 data requests each, 17 screens x 3 companies).
They compare as identical (r181-compare.py), so the comparison has no noise. The R181 build is compared against r180-a after
restoring the same snapshot.
