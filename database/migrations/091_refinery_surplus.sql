-- 091: Record metal the refiner supplied out of his own.
--
-- A furnace cannot make gold, so a refining job normally returns LESS fine
-- weight than it took in, and the shortfall is the refining loss. More coming
-- back means the refiner put some of his own metal in — usually so the shop
-- gets a round bar rather than an awkward fraction.
--
-- Until now that was refused outright, with the advice to "record the extra as
-- a separate purchase". It IS a purchase, and the karigar side of the workshop
-- had already learned to record it as one (see jw_wastage_split's surplus_fine)
-- while the refinery side still sent the shop away to key it in by hand — where
-- it would be missed, or valued at today's rate instead of the job's.
--
-- So the job now stores it, the way it already stores the loss:
--
--     surplus_fine_weight  fine metal that came back over and above the issue
--     surplus_amount       that weight at the rate the ISSUE was valued at
--
-- Valued at the issue's rate on purpose. Using the market rate of the day would
-- book a profit or loss on metal that only passed through a furnace.
--
-- Loss and surplus are mutually exclusive — one of the pair is always zero — so
-- nothing reading these has to decide between them.
--
-- Both default to 0, which is what every completed job holds today: under the
-- old rule a job with surplus could not be received at all, so there is no
-- history to reconstruct here.

ALTER TABLE `jewellery_refinery_jobs`
    ADD COLUMN `surplus_fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER `loss_amount`,
    ADD COLUMN `surplus_amount` DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER `surplus_fine_weight`;
